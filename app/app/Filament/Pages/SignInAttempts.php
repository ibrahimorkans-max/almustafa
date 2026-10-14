<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Security\GeoIp;
use App\Models\AuditLog;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Failed and blocked sign-ins of the mill, from the audit log: wrong passwords and codes, unknown
 * usernames, unrecognised passkeys, untrusted devices, networks outside the allow-list, lockdown.
 * With the IP's city, so a burst from an unexpected place stands out.
 */
class SignInAttempts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'Failed sign-ins';

    protected static ?string $title = 'Failed and blocked sign-ins';

    protected static ?string $slug = 'sign-in-attempts';

    protected static ?int $navigationSort = 31;

    protected static string $view = 'filament.pages.sign-in-attempts';

    public const REASONS = [
        'bad_password' => 'Wrong password', 'bad_mfa_code' => 'Wrong 6-digit code', 'unknown_user' => 'Unknown username',
        'passkey_not_recognised' => 'Passkey not recognised', 'device_not_trusted' => 'Device waiting for approval',
        'ip_not_allowed' => 'Network not allowed', 'lockdown' => 'System locked down', 'locked' => 'Account locked',
        'account_suspended' => 'Account suspended', 'account_revoked' => 'Account revoked', 'device_revoked' => 'Device revoked',
        'device_wiped' => 'Device wiped', 'device_wipe_pending' => 'Device being wiped', 'no_panel_access' => 'No back-office access',
        'agent_not_registered' => 'Gate agent not registered',
    ];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->hasPermission('session.view_all') || $user->hasPermission('audit.view'));
    }

    public function table(Table $table): Table
    {
        $geo = app(GeoIp::class);

        return $table
            ->query(AuditLog::query()->whereIn('action', ['auth.login.failed', 'auth.login.blocked']))
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i:s', 'Asia/Karachi')->sortable(),
                TextColumn::make('username')->label('Username')
                    ->state(fn (AuditLog $r) => (string) ($r->meta['username'] ?? ($r->subject_type !== null ? LoginAccount::query()->whereKey($r->subject_id)->value('username') : '—'))),
                TextColumn::make('reason')->label('What happened')->badge()
                    ->state(fn (AuditLog $r) => self::REASONS[$r->meta['reason'] ?? ''] ?? str_replace('_', ' ', (string) ($r->meta['reason'] ?? $r->action)))
                    ->color(fn (AuditLog $r) => $r->action === 'auth.login.blocked' ? 'warning' : 'danger'),
                TextColumn::make('ip')->label('IP')->state(fn (AuditLog $r) => $r->ip ?? ($r->meta['ip'] ?? '—'))->searchable(),
                TextColumn::make('place')->label('Location')
                    ->state(function (AuditLog $r) use ($geo): string {
                        $g = $geo->lookup($r->ip ?? ($r->meta['ip'] ?? null));
                        if ($g === null) {
                            return '—';
                        }

                        return $g['source'] === 'local' ? 'Mill network' : (implode(', ', array_filter([$g['city'], $g['country']])) ?: '—');
                    }),
                TextColumn::make('user_agent')->label('Browser')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('action')->label('Kind')->options(['auth.login.failed' => 'Failed (wrong details)', 'auth.login.blocked' => 'Blocked (rules)']),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->where('occurred_at', '>=', now()->subDays(90)));
    }
}
