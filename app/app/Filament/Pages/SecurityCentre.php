<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Security\Lockdown;
use App\Domain\Security\MaintenanceService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Entity;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cookie;

/**
 * Super Admin emergency controls: lockdown of everything or of one entity, and maintenance mode.
 * Every action needs the current 2FA code and is audited; owners are told.
 */
class SecurityCentre extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $title = 'Security centre';

    protected static ?string $slug = 'security-centre';

    protected static ?int $navigationSort = 95;

    protected static string $view = 'filament.pages.security-centre';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isSuperAdmin() && ! $user->hasPendingSecuritySetup();
    }

    /** @return list<object> */
    public function locks(): array
    {
        $names = Entity::query()->pluck('name', 'id');

        return array_map(function (object $l) use ($names) {
            $l->where = $l->entity_id ? ($names[$l->entity_id] ?? 'one entity') : 'Whole system';
            $l->by = LoginAccount::query()->whereKey($l->locked_by)->value('username');

            return $l;
        }, app(Lockdown::class)->activeLocks());
    }

    protected function getHeaderActions(): array
    {
        $code = TextInput::make('code')->label('Your current 2FA code')->required()->length(6)->numeric()->autocomplete('one-time-code');

        return [
            Action::make('lockdown')->label('Emergency lockdown')->color('danger')->icon('heroicon-o-lock-closed')
                ->modalHeading('Emergency lockdown')
                ->modalDescription('Signs everyone in the chosen scope out at once (browser, phone, PWA, gate agent) and blocks sign-in for all except Super Admins. Nothing is deleted. Owners are told by e-mail and in the app.')
                ->form([
                    Select::make('scope')->label('Lock')->required()->default('system')
                        ->options(['system' => 'Whole system (all entities)'] + Entity::query()->orderBy('code')->get()->mapWithKeys(fn (Entity $e) => [$e->id => "{$e->code} — {$e->name}"])->all()),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(1000),
                    $code,
                ])
                ->requiresConfirmation()
                ->action(fn (array $data) => $this->attempt(fn () => app(Lockdown::class)->lock(
                    $data['scope'] === 'system' ? null : $data['scope'], $data['reason'], (string) $data['code'],
                ), 'Lockdown started. Everyone in scope has been signed out.')),
            Action::make('unlock')->label('Lift lockdown')->color('success')->icon('heroicon-o-lock-open')
                ->visible(fn () => app(Lockdown::class)->activeLocks() !== [])
                ->form([
                    Select::make('lock_id')->label('Lockdown')->required()
                        ->options(fn () => collect($this->locks())->mapWithKeys(fn ($l) => [$l->id => "{$l->where} (since ".CarbonImmutable::parse($l->locked_at, 'UTC')->timezone('Asia/Kolkata')->format('d M H:i').' IST)'])->all()),
                    Textarea::make('reason')->label('Why it is safe to unlock')->required()->minLength(5)->maxLength(1000),
                    $code,
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(Lockdown::class)->unlock(
                    $data['lock_id'], $data['reason'], (string) $data['code'],
                ), 'Lockdown lifted. People can sign in again.')),
            ...$this->maintenanceActions(),
        ];
    }

    /** @return list<object> */
    public function maintenanceWindows(): array
    {
        return app(MaintenanceService::class)->current();
    }

    /** @return list<Action> */
    protected function maintenanceActions(): array
    {
        return [
            Action::make('maintenance')->label('Schedule maintenance')->color('warning')->icon('heroicon-o-wrench-screwdriver')
                ->modalDescription('Everyone except holders of the secret link sees your message and the end time; the API answers 503 with Retry-After. Offline devices keep queueing.')
                ->form([
                    TextInput::make('message')->required()->minLength(5)->maxLength(255)
                        ->default('We are updating the system.'),
                    DateTimePicker::make('starts_at')->label('Starts')->required()->seconds(false)->default(now()),
                    DateTimePicker::make('ends_at')->label('Expected end')->required()->seconds(false)->default(now()->addHour()),
                ])
                ->action(function (array $data): void {
                    try {
                        $r = app(MaintenanceService::class)->schedule($data['message'],
                            CarbonImmutable::parse($data['starts_at']), CarbonImmutable::parse($data['ends_at']));
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    // This browser keeps working during the window; other devices use the link.
                    Cookie::queue(Cookie::make(MaintenanceService::BYPASS_COOKIE, $r['bypass_token'],
                        (int) now()->diffInMinutes(CarbonImmutable::parse($data['ends_at']), true) + 5, secure: request()->isSecure(), httpOnly: true, sameSite: 'strict'));
                    Notification::make()->title('Maintenance scheduled.')->success()->persistent()
                        ->body('Your bypass link (shown once — keep it private): '.route('maintenance.bypass', $r['bypass_token']))
                        ->send();
                }),
            Action::make('endMaintenance')->label('End maintenance now')->color('gray')->icon('heroicon-o-play')
                ->visible(fn () => $this->maintenanceWindows() !== [])
                ->form([Select::make('window_id')->label('Window')->required()
                    ->options(fn (): array => collect($this->maintenanceWindows())->mapWithKeys(fn (object $w): array => [(string) $w->id => (string) $w->message])->all())])
                ->action(fn (array $data) => $this->attempt(fn () => app(MaintenanceService::class)->end($data['window_id']), 'Maintenance ended.')),
        ];
    }

    private function attempt(callable $do, string $ok): void
    {
        try {
            $do();
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($ok)->success()->send();
    }
}
