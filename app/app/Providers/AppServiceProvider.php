<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Auth\ActorContext;
use App\Domain\Auth\ApiTokenGuard;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\SessionService;
use App\Domain\Auth\TenantAwareUserProvider;
use App\Domain\Gst\EInvoiceProvider;
use App\Domain\Gst\SandboxEInvoiceProvider;
use App\Domain\Ops\OpsAlerts;
use App\Domain\Sync\SyncRegistry;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Pages\SwitchContext;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Http\Middleware\RequireWorkingEntityForCreate;
use App\Models\Accounts;
use App\Models\Alert;
use App\Models\AuthSession;
use App\Models\Claims\Claim;
use App\Models\Gate\DeductionRule;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Scale;
use App\Models\Inventory;
use App\Models\LoginAccount;
use App\Models\Masters;
use App\Models\Pricing\MarkupSetting;
use App\Models\Pricing\PriceList;
use App\Models\Procurement\PaddyRequirement;
use App\Models\Procurement\Quote;
use App\Models\Procurement\Sauda;
use App\Models\Production;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\SalesBill;
use App\Policies;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request / per job state (flushed between Octane requests and queue jobs).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(ActorContext::class);
        $this->app->scoped(SyncRegistry::class);
        $this->app->singleton(Google2FA::class);
        // E-invoice / e-way bill: sandbox until the mill's GSP adapter and credentials are configured.
        $this->app->bind(EInvoiceProvider::class, fn () => match (config('ricemill.gst.provider')) {
            'sandbox' => new SandboxEInvoiceProvider,
            default => throw new \RuntimeException('Unknown e-invoice provider '.config('ricemill.gst.provider').'; add its adapter first.'),
        });
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        $this->registerAuth();
        $this->registerRateLimits();
        $this->registerQueueContextReset();
        $this->registerOpsAlerts();
        $this->registerFriendlyContextErrors();

        Password::defaults(fn () => Password::min((int) config('ricemill.auth.password_min_length'))
            ->letters()->mixedCase()->numbers()->symbols());
    }

    private function registerAuth(): void
    {
        Auth::provider('tenant-eloquent', fn ($app, array $config) => new TenantAwareUserProvider(
            $app['hash'],
            $config['model'],
            $app->make(TenantContext::class),
            $app->make(AuthContextBinder::class),
        ));

        Auth::viaRequest('ricemill-token', fn (Request $request) => $this->app->make(ApiTokenGuard::class)($request));

        // Master data lives in App\Models\Masters, outside policy auto-discovery.
        foreach ([
            Masters\Party::class => Policies\Masters\PartyPolicy::class,
            Masters\Vehicle::class => Policies\Masters\VehiclePolicy::class,
            Masters\Driver::class => Policies\Masters\DriverPolicy::class,
            Masters\Product::class => Policies\Masters\ProductPolicy::class,
            Masters\Brand::class => Policies\Masters\BrandPolicy::class,
            Masters\Godown::class => Policies\Masters\GodownPolicy::class,
            Masters\TaxRate::class => Policies\Masters\TaxRatePolicy::class,
            Masters\PartyBankAccount::class => Policies\PartyBankAccountPolicy::class,
            PaddyRequirement::class => Policies\Phase3\PaddyRequirementPolicy::class,
            Quote::class => Policies\Phase3\QuotePolicy::class,
            Sauda::class => Policies\Phase3\SaudaPolicy::class,
            GateEntry::class => Policies\Phase3\GateEntryPolicy::class,
            Scale::class => Policies\Phase3\ScalePolicy::class,
            DeductionRule::class => Policies\Phase3\DeductionRulePolicy::class,
            Inventory\StockBalance::class => Policies\Phase4\StockBalancePolicy::class,
            Inventory\StockEntry::class => Policies\Phase4\StockEntryPolicy::class,
            Inventory\Requisition::class => Policies\Phase4\RequisitionPolicy::class,
            Inventory\StockCount::class => Policies\Phase4\StockCountPolicy::class,
            Production\ProductionBatch::class => Policies\Phase4\ProductionBatchPolicy::class,
            Production\OutturnStandard::class => Policies\Phase4\OutturnStandardPolicy::class,
            Alert::class => Policies\Phase4\AlertPolicy::class,
            Accounts\Voucher::class => Policies\Phase5\VoucherPolicy::class,
            Accounts\PurchaseBill::class => Policies\Phase5\PurchaseBillPolicy::class,
            Accounts\Payment::class => Policies\Phase5\PaymentPolicy::class,
            Accounts\BankFile::class => Policies\Phase5\BankFilePolicy::class,
            Accounts\BankStatementLine::class => Policies\Phase5\BankStatementLinePolicy::class,
            Accounts\PeriodLock::class => Policies\Phase5\PeriodLockPolicy::class,
            Accounts\LedgerAccount::class => Policies\Phase5\LedgerAccountPolicy::class,
            DispatchOrder::class => Policies\Phase6\DispatchOrderPolicy::class,
            SalesBill::class => Policies\Phase6\SalesBillPolicy::class,
            Claim::class => Policies\Phase7\ClaimPolicy::class,
            PriceList::class => Policies\Phase7\PriceListPolicy::class,
            MarkupSetting::class => Policies\Phase7\MarkupSettingPolicy::class,
        ] as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Permission codes ("deal.approve") are answered from the account's role grants.
        Gate::before(function ($user, string $ability): ?bool {
            if ($user instanceof LoginAccount && PermissionCatalog::exists($ability)) {
                return $user->hasPermission($ability);
            }

            return null;
        });

        // Web logout ends the matching auth session.
        Event::listen(Logout::class, function (Logout $event): void {
            $request = request();
            $sessionId = $request->hasSession() ? $request->session()->get(EnsureWebAuthSession::SESSION_KEY) : null;
            if ($sessionId === null || ! $event->user instanceof LoginAccount) {
                return;
            }

            $session = $this->app->make(TenantContext::class)->runAsSystem(fn () => AuthSession::query()->find($sessionId));
            if ($session !== null && $session->login_account_id === $event->user->getAuthIdentifier()) {
                $this->app->make(SessionService::class)->revoke($session, 'logout');
            }
        });
    }

    private function registerRateLimits(): void
    {
        RateLimiter::for('api-login', fn (Request $request) => [
            Limit::perMinute(20)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('user:'.mb_strtolower((string) $request->input('username')).'|'.$request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }

    /**
     * Long-running workers must never carry one job's entity into the next. A job on the
     * `sync` connection runs inline inside the current request, whose context must survive it.
     */
    private function registerQueueContextReset(): void
    {
        $reset = function (JobProcessing|JobProcessed $event): void {
            if ($event->connectionName === 'sync') {
                return;
            }
            $this->app->make(TenantContext::class)->clear();
            $this->app->make(ActorContext::class)->clear();
        };

        Event::listen(JobProcessing::class, $reset);
        Event::listen(JobProcessed::class, $reset);
    }

    /**
     * A button pressed by a Super Admin in "All firms" mode that has to write into one firm explains
     * itself instead of showing an error page. Nothing is written; the rule itself stays.
     */
    private function registerFriendlyContextErrors(): void
    {
        \Livewire\on('exception', function ($component, Throwable $e, callable $stopPropagation): void {
            if ($e instanceof MissingTenantContext) {
                Notification::make()->title(RequireWorkingEntityForCreate::MESSAGE)->warning()->persistent()
                    ->actions([NotificationAction::make('switch')->label('Switch entity')->url(SwitchContext::getUrl())])
                    ->send();
                $stopPropagation();
            }
        });
    }

    /** A failed scheduled task or queued job alerts the Super Admins / owners (ops monitoring). */
    private function registerOpsAlerts(): void
    {
        $alert = fn (string $what, string $detail) => $this->app->make(OpsAlerts::class)->raise('ops_failure', 'critical',
            mb_substr("Failed: {$what}", 0, 200), mb_substr($detail, 0, 1000), ['task' => $what]);

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) use ($alert): void {
            if ($event->task->exitCode !== null && $event->task->exitCode !== 0) {
                $alert((string) ($event->task->description ?? $event->task->command), "Exit code {$event->task->exitCode}. See storage/logs.");
            }
        });
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $alert(
            (string) ($event->task->description ?? $event->task->command), $event->exception->getMessage()));
        Event::listen(JobFailed::class, fn (JobFailed $event) => $alert($event->job->resolveName(), $event->exception->getMessage()));
    }
}
