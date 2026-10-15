<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\AccountStatusService;
use App\Domain\Auth\MfaService;
use App\Domain\Auth\SessionService;
use App\Domain\Security\DuressService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\LoginAccountResource\Pages;
use App\Filament\Resources\LoginAccountResource\RelationManagers\IpAllowlistRelationManager;
use App\Models\LoginAccount;
use App\Models\Role;
use Filament\Facades\Filament;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class LoginAccountResource extends Resource
{
    protected static ?string $model = LoginAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $modelLabel = 'user';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $slug = 'users';

    protected static ?int $navigationSort = 20;

    /**
     * Roles the current user may assign (privilege-escalation guard).
     *
     * @return array<string, string> role id => label
     */
    public static function assignableRoles(): array
    {
        /** @var LoginAccount $me */
        $me = Filament::auth()->user();
        $entityId = app(TenantContext::class)->entityId();
        if ($entityId === null) {
            return []; // Owner in "all entities" mode must pick a working entity first.
        }

        return Role::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->orderBy('name')->get()
            ->filter(fn (Role $role) => Gate::forUser($me)->allows('assignRole', [LoginAccount::class, $role]))
            ->mapWithKeys(fn (Role $role) => [$role->id => $role->name.($role->mfa_required ? ' (2FA required)' : '')])
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Person')->columns(3)->visibleOn('create')->schema([
                TextInput::make('full_name')->required()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(20),
                TextInput::make('email')->email()->maxLength(255),
            ]),
            Section::make('Login account')->columns(2)->schema([
                TextInput::make('username')->required()->maxLength(64)
                    ->regex('/^[a-z0-9._-]{3,64}$/')
                    ->helperText('Lower-case letters, digits, dot, dash, underscore. Unique across all entities.')
                    // Usernames are unique across ALL entities, so check outside the tenant scope.
                    ->rule(fn (?LoginAccount $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        $taken = app(TenantContext::class)->runAsSystem(fn () => LoginAccount::query()
                            ->where('username', mb_strtolower((string) $value))
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                            ->exists());
                        if ($taken) {
                            $fail('This username is already taken.');
                        }
                    })
                    ->disabledOn('edit'),
                Select::make('role_id')->label('Role')->required()
                    ->options(fn () => static::assignableRoles())
                    ->in(fn () => array_keys(static::assignableRoles())),
                TextInput::make('password')->label('Temporary password')->password()->revealable()
                    ->required()->rule(Password::defaults())->visibleOn('create')
                    ->helperText('The user must change it at first sign-in.'),
                Toggle::make('require_trusted_device')->label('Only from approved devices')
                    ->helperText('A new phone or computer must first be approved by the Super Admin or the Owner. Applies when Security → Sign-in rules is set to "selected accounts"; with "everyone" it applies to all accounts anyway.')
                    ->disabled(fn (?LoginAccount $record) => $record !== null && $record->person_id === auth()->user()?->person_id)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('person.full_name')->label('Name')->searchable(),
                TextColumn::make('username')->searchable()->sortable(),
                TextColumn::make('role.name')->label('Role')->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                IconColumn::make('mfa_confirmed_at')->label('2FA')->boolean()
                    ->getStateUsing(fn (LoginAccount $r) => $r->mfa_confirmed_at !== null),
                TextColumn::make('locked_until')->label('Locked until')->dateTime()->placeholder('—'),
                TextColumn::make('duress_flagged_at')->label('Duress alert')->dateTime()->color('danger')->placeholder('—')
                    ->toggleable(),
                TextColumn::make('last_login_at')->dateTime()->sortable()->placeholder('never'),
            ])
            ->defaultSort('username')
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended', 'revoked' => 'Revoked']),
                SelectFilter::make('role_id')->label('Role')->relationship('role', 'name'),
            ])
            ->actions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('suspend')->icon('heroicon-o-pause-circle')->color('warning')
                        ->visible(fn (LoginAccount $r) => $r->isActive() && static::canManageSecurity($r))
                        ->modalDescription('Signs the user out everywhere at once (browser, phone, gate PC). They see "Access disabled, contact your administrator" until reinstated.')
                        ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                        ->requiresConfirmation()
                        ->action(fn (LoginAccount $r, array $data) => static::changeAccess($r, 'suspend', $data['reason'])),
                    Action::make('revoke')->label('Revoke for good')->icon('heroicon-o-no-symbol')->color('danger')
                        ->visible(fn (LoginAccount $r) => $r->status !== LoginAccount::STATUS_REVOKED && static::canManageSecurity($r))
                        ->modalDescription('Permanent: the account can never be used again (history is kept). Use when the person has left.')
                        ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                        ->requiresConfirmation()
                        ->action(fn (LoginAccount $r, array $data) => static::changeAccess($r, 'revoke', $data['reason'])),
                    Action::make('reinstate')->icon('heroicon-o-check-circle')->color('success')
                        ->visible(fn (LoginAccount $r) => $r->status === LoginAccount::STATUS_SUSPENDED && static::canManageSecurity($r))
                        ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                        ->action(fn (LoginAccount $r, array $data) => static::changeAccess($r, 'reinstate', $data['reason'])),
                    Action::make('unlock')->icon('heroicon-o-lock-open')
                        ->visible(fn (LoginAccount $r) => $r->isLocked() && static::canManageSecurity($r))
                        ->requiresConfirmation()
                        ->modalDescription(fn (LoginAccount $r) => $r->needs_admin_unlock
                            ? "Locked {$r->lockouts_24h} times within 24 hours — check with the person that it was really them before unlocking."
                            : null)
                        ->action(function (LoginAccount $r): void {
                            $r->forceFill(['locked_until' => null, 'failed_attempts' => 0, 'needs_admin_unlock' => false, 'lockouts_24h' => 0])->save();
                            app(AuditLogger::class)->record('auth.account.unlocked', $r);
                        }),
                    Action::make('clearDuress')->label('Clear duress alert')->icon('heroicon-o-exclamation-triangle')->color('danger')
                        ->visible(fn (LoginAccount $r) => $r->duress_flagged_at !== null && static::canManageSecurity($r))
                        ->modalDescription('Only after you have spoken to the person (by phone or in person) and re-verified who they are. Reinstate the account separately if it was suspended.')
                        ->form([Textarea::make('note')->label('How the person was reached and verified')->required()->minLength(10)->maxLength(1000)])
                        ->action(function (LoginAccount $r, array $data): void {
                            app(DuressService::class)->clearFlag($r, $data['note']);
                            Notification::make()->title('Duress alert cleared.')->success()->send();
                        }),
                    Action::make('resetPassword')->label('Reset password')->icon('heroicon-o-key')
                        ->visible(fn (LoginAccount $r) => static::canManageSecurity($r))
                        ->form([
                            TextInput::make('password')->label('New temporary password')->password()->revealable()
                                ->required()->rule(Password::defaults())
                                ->default(fn () => Str::password(16)),
                            Textarea::make('reason')->required()->maxLength(500),
                        ])
                        ->action(function (LoginAccount $r, array $data): void {
                            DB::transaction(function () use ($r, $data): void {
                                $r->password = $data['password'];
                                $r->must_change_password = true;
                                $r->save();
                                app(SessionService::class)->revokeAllFor($r, 'password_reset_by_admin');
                                app(AuditLogger::class)->record('auth.password.reset_by_admin', $r, meta: ['reason' => $data['reason']]);
                            });
                            Notification::make()->title('Password reset. All sessions of this user were signed out.')->success()->send();
                        }),
                    Action::make('resetMfa')->label('Reset 2FA')->icon('heroicon-o-shield-exclamation')->color('danger')
                        ->visible(fn (LoginAccount $r) => $r->hasMfa() && static::canManageSecurity($r))
                        ->form([Textarea::make('reason')->required()->maxLength(500)])
                        ->requiresConfirmation()
                        ->action(function (LoginAccount $r, array $data): void {
                            app(MfaService::class)->reset($r, $data['reason']);
                            app(SessionService::class)->revokeAllFor($r, 'mfa_reset_by_admin');
                        }),
                ]),
            ])
            ->bulkActions([]);
    }

    public static function canManageSecurity(LoginAccount $record): bool
    {
        return Gate::allows('manageSecurity', $record);
    }

    /** @param  'suspend'|'revoke'|'reinstate'  $change */
    protected static function changeAccess(LoginAccount $record, string $change, string $reason): void
    {
        try {
            $account = app(AccountStatusService::class)->{$change}($record, $reason);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title("{$account->username} is now {$account->status}.")
            ->body($account->isActive() ? null : 'All of their sessions and devices were signed out.')->success()->send();
    }

    public static function getRelations(): array
    {
        return [IpAllowlistRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoginAccounts::route('/'),
            'create' => Pages\CreateLoginAccount::route('/create'),
            'edit' => Pages\EditLoginAccount::route('/{record}/edit'),
        ];
    }
}
