<?php

declare(strict_types=1);

namespace App\Filament\Pages\Platform;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\AccountStatusService;
use App\Domain\Auth\SessionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Models\Role;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Platform → All users (SaaS owner): every user of every company and mill in one place — add one to any mill, change
 * a role, suspend, revoke (accounts are never deleted: their records stay theirs), reinstate, reset a password, and
 * see each user's sign-ins and activity. Every change is in the audit log with the reason.
 */
class PlatformUsersPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'All users';

    protected static ?string $title = 'Users of every company and mill';

    protected static ?string $slug = 'platform/users';

    protected static ?int $navigationSort = 11;

    protected static string $view = 'filament.pages.platform.users';

    public string $search = '';

    public ?string $company = null;

    public ?string $status = null;

    public int $limit = 100;

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->isPlatformAdmin();
    }

    /** @return list<array<string, mixed>> */
    public function users(): array
    {
        return $this->sys(function (): array {
            $mills = Entity::query()->get(['id', 'name', 'code', 'customer_id'])->keyBy('id');
            $companies = Customer::query()->pluck('name', 'id');
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($this->search))).'%';

            return LoginAccount::query()->with(['person', 'role'])
                ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(username) LIKE ?', [$term])
                    ->orWhereHas('person', fn ($p) => $p->whereRaw('LOWER(full_name) LIKE ?', [$term]))))
                ->when($this->company, fn ($q) => $q->whereIn('entity_id', $mills->where('customer_id', $this->company)->keys()))
                ->when($this->status, fn ($q) => $q->where('status', $this->status))
                ->orderBy('username')->limit($this->limit)->get()
                ->map(function (LoginAccount $a) use ($mills, $companies): array {
                    $mill = $mills->get($a->getAttribute('entity_id') ?? $a->person?->getAttribute('entity_id'));

                    return ['id' => $a->id, 'username' => $a->username, 'name' => $a->person?->full_name, 'role' => $a->role?->name,
                        'mill' => $mill ? $mill->name.' ('.$mill->code.')' : '—', 'company' => $mill ? ($companies[$mill->customer_id] ?? '—') : '—',
                        'status' => $a->status, 'last_login' => $a->last_login_at?->timezone('Asia/Karachi')->format('d M Y H:i'),
                        'platform' => $a->isPlatformAdmin()];
                })->all();
        });
    }

    /** @return array<string, string> */
    public function companies(): array
    {
        return $this->sys(fn () => Customer::query()->orderBy('name')->pluck('name', 'id')->all());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function sys(callable $fn): mixed
    {
        return app(TenantContext::class)->runAsSystem($fn);
    }

    private function account(array $arguments): LoginAccount
    {
        $a = $this->sys(fn () => LoginAccount::query()->with(['person', 'role'])->find($arguments['user'] ?? ''));
        if (! $a instanceof LoginAccount) {
            throw new BusinessRuleViolation('User not found.');
        }
        if ($a->isPlatformAdmin() && $a->id !== Filament::auth()->id()) {
            throw new BusinessRuleViolation('Another SaaS owner account is changed from its own sign-in only.');
        }

        return $a;
    }

    private function done(callable $fn, string $ok): void
    {
        try {
            $this->sys($fn);
            Notification::make()->success()->title($ok)->send();
        } catch (BusinessRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function addUserAction(): Action
    {
        return Action::make('addUser')->label('Add a user')->icon('heroicon-o-user-plus')
            ->form([
                Grid::make(2)->schema([
                    Select::make('entity_id')->label('Mill')->required()->searchable()->live()
                        ->options(fn () => $this->sys(fn () => Entity::query()->with('customer')->orderBy('name')->get()
                            ->mapWithKeys(fn (Entity $e) => [$e->id => $e->name.' — '.($e->customer?->name ?? '')])->all())),
                    Select::make('role_id')->label('Role')->required()
                        ->options(fn (Get $get) => $get('entity_id') ? $this->sys(fn () => Role::query()->where('entity_id', $get('entity_id'))->orderBy('name')->pluck('name', 'id')->all()) : []),
                    TextInput::make('full_name')->required()->maxLength(160),
                    TextInput::make('username')->required()->maxLength(60)->regex('/^[a-z0-9._-]{3,60}$/'),
                    TextInput::make('phone')->maxLength(20),
                    TextInput::make('email')->email()->maxLength(160),
                    TextInput::make('password')->label('Temporary password (changed at first sign-in)')->password()->revealable()->required()
                        ->rule(Password::defaults())->default(fn () => Str::password(16)),
                ]),
            ])
            ->action(fn (array $data) => $this->done(function () use ($data): void {
                if (LoginAccount::query()->where('username', $data['username'])->exists()) {
                    throw new BusinessRuleViolation('That username is taken.');
                }
                $role = Role::query()->where('entity_id', $data['entity_id'])->findOrFail($data['role_id']);
                app(TenantContext::class)->runInEntity($data['entity_id'], function () use ($data, $role): void {
                    DB::transaction(function () use ($data, $role): void {
                        $person = Person::query()->create(['full_name' => $data['full_name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null]);
                        $a = LoginAccount::query()->create(['person_id' => $person->id, 'role_id' => $role->id, 'username' => $data['username'],
                            'password' => $data['password'], 'status' => LoginAccount::STATUS_ACTIVE, 'must_change_password' => true]);
                        app(AuditLogger::class)->record('platform.user_added', $a, null, ['username' => $a->username, 'role' => $role->code]);
                    });
                });
            }, 'User added. They change the password at first sign-in.'));
    }

    public function roleAction(): Action
    {
        return Action::make('role')->label('Role')->icon('heroicon-m-identification')->size('xs')->color('gray')
            ->fillForm(fn (array $arguments) => ['role_id' => $this->account($arguments)->role_id])
            ->form(fn (array $arguments) => [
                Select::make('role_id')->label('Role')->required()->options(fn () => $this->sys(fn () => Role::query()
                    ->where('entity_id', $this->account($arguments)->role?->getAttribute('entity_id'))->orderBy('name')->pluck('name', 'id')->all())),
                Textarea::make('reason')->required()->minLength(5)->maxLength(500),
            ])
            ->action(fn (array $data, array $arguments) => $this->done(function () use ($data, $arguments): void {
                $a = $this->account($arguments);
                $role = Role::query()->where('entity_id', $a->role?->getAttribute('entity_id'))->findOrFail($data['role_id']);
                $before = $a->role?->code;
                $a->role_id = $role->id;
                $a->save();
                app(SessionService::class)->revokeAllFor($a, 'role_changed_by_platform');
                app(AuditLogger::class)->record('platform.user_role', $a, ['role' => $before], ['role' => $role->code], ['reason' => $data['reason']]);
            }, 'Role changed. The user signs in again.'));
    }

    public function accessAction(): Action
    {
        return Action::make('access')->label('Access')->icon('heroicon-m-lock-closed')->size('xs')->color('warning')
            ->form([
                Select::make('change')->required()->options(['suspend' => 'Suspend (can be reinstated)', 'revoke' => 'Revoke for good (the account stays, its records stay)', 'reinstate' => 'Reinstate']),
                Textarea::make('reason')->required()->minLength(5)->maxLength(500),
            ])
            ->action(fn (array $data, array $arguments) => $this->done(fn () => app(AccountStatusService::class)->{$data['change']}($this->account($arguments), $data['reason']), 'Access changed.'));
    }

    public function passwordAction(): Action
    {
        return Action::make('password')->label('Password')->icon('heroicon-m-key')->size('xs')->color('gray')
            ->form([
                TextInput::make('password')->label('New temporary password')->password()->revealable()->required()->rule(Password::defaults())->default(fn () => Str::password(16)),
                Textarea::make('reason')->required()->minLength(5)->maxLength(500),
            ])
            ->action(fn (array $data, array $arguments) => $this->done(function () use ($data, $arguments): void {
                $a = $this->account($arguments);
                DB::transaction(function () use ($a, $data): void {
                    $a->password = $data['password'];
                    $a->must_change_password = true;
                    $a->save();
                    app(SessionService::class)->revokeAllFor($a, 'password_reset_by_platform');
                    app(AuditLogger::class)->record('auth.password.reset_by_admin', $a, meta: ['reason' => $data['reason'], 'by' => 'platform']);
                });
            }, 'Password reset; the user changes it at the next sign-in.'));
    }

    public function historyAction(): Action
    {
        return Action::make('history')->label('Sign-ins & activity')->icon('heroicon-m-clock')->size('xs')->color('gray')
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalWidth('4xl')
            ->form(fn (array $arguments) => [Placeholder::make('h')->hiddenLabel()->content(fn () => new HtmlString($this->sys(function () use ($arguments): string {
                $a = $this->account($arguments);
                $sessions = AuthSession::query()->where('login_account_id', $a->id)->latest('created_at')->limit(20)->get();
                $acts = AuditLog::query()->where('actor_login_account_id', $a->id)->latest('id')->limit(30)->get();
                $t = fn ($d) => e($d?->timezone('Asia/Karachi')->format('d M Y H:i') ?? '—');

                return '<p class="font-semibold">Sign-ins</p><table class="w-full text-xs"><tr class="text-left text-gray-500"><th>When</th><th>From</th><th>Device</th><th>Ended</th></tr>'
                    .$sessions->map(fn ($s) => '<tr><td>'.$t($s->created_at).'</td><td>'.e((string) ($s->ip ?? $s->getAttribute('ip_address') ?? '')).'</td><td>'.e(Str::limit((string) ($s->getAttribute('user_agent') ?? $s->getAttribute('platform') ?? ''), 50)).'</td><td>'.$t($s->getAttribute('revoked_at') ?? $s->getAttribute('ended_at')).'</td></tr>')->implode('')
                    .'</table><p class="mt-3 font-semibold">Activity</p><table class="w-full text-xs"><tr class="text-left text-gray-500"><th>When</th><th>What</th></tr>'
                    .$acts->map(fn ($l) => '<tr><td>'.$t($l->created_at).'</td><td>'.e((string) $l->action).'</td></tr>')->implode('').'</table>';
            })))]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->addUserAction()];
    }
}
