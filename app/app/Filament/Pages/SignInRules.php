<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Approvals\Actions\TrustDevice;
use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The mill's sign-in rules, set by the mill's Super Admin (or the Owner): whether a new phone or computer must be approved
 * before it can be used (everyone / selected accounts / off). IP limits are per account under
 * Users; Owner rules come from the server configuration and are not changed here.
 *
 * @property Form $form
 */
class SignInRules extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'Sign-in rules';

    protected static ?string $title = 'Sign-in rules';

    protected static ?string $slug = 'sign-in-rules';

    protected static ?int $navigationSort = 29;

    protected static string $view = 'filament.pages.sign-in-rules';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('settings.manage');
    }

    /** The rule belongs to one mill; an Owner in "All firms" mode chooses one first. */
    public function millChosen(): bool
    {
        return app(TenantContext::class)->entityId() !== null;
    }

    public function switchUrl(): string
    {
        return SwitchContext::getUrl();
    }

    public function mount(): void
    {
        if (! $this->millChosen()) {
            return;
        }
        $this->form->fill(['trusted_devices' => app(SettingsService::class)->trustedDevicesMode(app(TenantContext::class)->entityId())]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Radio::make('trusted_devices')->label('New phones and computers')->required()
                ->options([
                    'everyone' => 'Everyone: a device used for the first time must be approved before it can sign in',
                    'selected' => 'Selected accounts only (switched on per account under Users)',
                    'off' => 'Off: any device with the right password, code or passkey',
                ])
                ->descriptions([
                    'everyone' => 'Strictest. Attendance-only employee logins are left out: HR already approves their phone under Attendance devices.',
                    'selected' => 'For example only the Super Admin, accountant and HR.',
                    'off' => 'Not recommended once the mill is live.',
                ]),
        ]);
    }

    public function pendingDevices(): int
    {
        return ApprovalRequest::query()->where('action', TrustDevice::KEY)->where('status', ApprovalRequest::STATUS_PENDING)->count();
    }

    public function approvalsUrl(): string
    {
        return ApprovalRequestResource::getUrl();
    }

    public function save(): void
    {
        abort_unless(static::canAccess() && $this->millChosen(), 403);
        $entityId = (string) app(TenantContext::class)->entityId();
        $mode = (string) $this->form->getState()['trusted_devices'];
        $before = app(SettingsService::class)->trustedDevicesMode($entityId);
        app(SettingsService::class)->put(SettingsService::TRUSTED_DEVICES, $mode, $entityId);
        app(AuditLogger::class)->record('security.sign_in_rules_changed', null, meta: ['trusted_devices' => ['from' => $before, 'to' => $mode]]);
        Notification::make()->title('Sign-in rules saved. They apply from the next sign-in.')->success()->send();
    }
}
