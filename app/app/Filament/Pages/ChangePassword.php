<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Auth\SessionService;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * @property Form $form
 */
class ChangePassword extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'change-password';

    protected static ?string $title = 'Change password';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.change-password';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('current_password')->password()->required()->autocomplete('current-password'),
            TextInput::make('password')->label('New password')->password()->revealable()->required()
                ->rule(Password::defaults())->confirmed()->different('current_password')->autocomplete('new-password'),
            TextInput::make('password_confirmation')->label('Repeat new password')->password()->required(),
        ])->statePath('data');
    }

    public function mustChange(): bool
    {
        return $this->account()->must_change_password;
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $account = $this->account();

        if (! Hash::check((string) $data['current_password'], $account->password)) {
            throw ValidationException::withMessages(['data.current_password' => 'The current password is not correct.']);
        }
        if ($account->duress_pin_hash !== null && Hash::check((string) $data['password'], $account->duress_pin_hash)) {
            throw ValidationException::withMessages(['data.password' => 'The new password must be different from your duress PIN.']);
        }

        DB::transaction(function () use ($account, $data): void {
            $account->password = (string) $data['password'];
            $account->must_change_password = false;
            $account->save();

            $current = app(ActorContext::class)->session();
            $revoked = app(SessionService::class)->revokeAllFor($account, 'password_changed', $current?->id);
            app(AuditLogger::class)->record('auth.password.changed', $account, meta: ['other_sessions_revoked' => $revoked]);
        });

        // Keep this browser signed in (AuthenticateSession compares the stored password hash).
        session()->put('password_hash_'.Filament::getAuthGuard(), $account->getAuthPassword());

        $this->form->fill();
        Notification::make()->title('Password changed. Other sessions were signed out.')->success()->send();
        $this->redirect(Filament::getUrl());
    }

    private function account(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }
}
