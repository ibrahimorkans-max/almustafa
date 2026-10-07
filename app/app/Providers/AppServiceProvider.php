<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Auth\ActorContext;
use App\Domain\Auth\ApiTokenGuard;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\SessionService;
use App\Domain\Auth\TenantAwareUserProvider;
use App\Domain\Sync\SyncRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\Gate\DeductionRule;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Scale;
use App\Models\LoginAccount;
use App\Models\Masters;
use App\Models\Procurement\PaddyRequirement;
use App\Models\Procurement\Quote;
use App\Models\Procurement\Sauda;
use App\Policies;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use PragmaRX\Google2FA\Google2FA;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request / per job state (flushed between Octane requests and queue jobs).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(ActorContext::class);
        $this->app->scoped(SyncRegistry::class);
        $this->app->singleton(Google2FA::class);
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        $this->registerAuth();
        $this->registerRateLimits();
        $this->registerQueueContextReset();

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

    /** Long-running workers must never carry one job's entity into the next. */
    private function registerQueueContextReset(): void
    {
        $reset = function (): void {
            $this->app->make(TenantContext::class)->clear();
            $this->app->make(ActorContext::class)->clear();
        };

        Event::listen(JobProcessing::class, $reset);
        Event::listen(JobProcessed::class, $reset);
    }
}
