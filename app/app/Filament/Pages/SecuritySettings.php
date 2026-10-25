<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Settings\SettingsService;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Global settings (Owner only).
 *
 * @property Form $form
 */
class SecuritySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.security-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        // Sign-in for every customer: the platform admin's.
        return $user instanceof LoginAccount && $user->isPlatformAdmin() && $user->hasPermission('settings.manage');
    }

    public function mount(): void
    {
        $this->form->fill(['login_mode' => app(SettingsService::class)->loginMode()]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Radio::make('login_mode')
                ->label('Login mode')
                ->options([
                    SettingsService::LOGIN_MODE_MULTI_ACCOUNT => 'Separate login per entity (each account has its own username, password and 2FA)',
                    SettingsService::LOGIN_MODE_SWITCHER => 'Single login with entity switcher (a person can switch between their accounts)',
                ])
                ->required(),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        app(SettingsService::class)->put(SettingsService::LOGIN_MODE, $this->form->getState()['login_mode']);
        Notification::make()->title('Settings saved')->success()->send();
    }
}
