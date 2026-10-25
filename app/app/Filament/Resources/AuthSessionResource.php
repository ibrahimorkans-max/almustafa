<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Auth\ActorContext;
use App\Domain\Auth\SessionService;
use App\Domain\Security\GeoIp;
use App\Filament\Resources\AuthSessionResource\Pages;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Sign-ins: who, on what browser and system, from which IP and place, by password or passkey, and
 * whether the session is still open. Everyone sees their own; `session.view_all` sees the mill's.
 * Location: the city of the IP (offline database) and, when the person allowed it, the browser's
 * own position on the map.
 */
class AuthSessionResource extends Resource
{
    protected static ?string $model = AuthSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'Sessions';

    protected static ?string $slug = 'sessions';

    protected static ?int $navigationSort = 20;

    /** @return Builder<AuthSession> */
    public static function getEloquentQuery(): Builder
    {
        /** @var LoginAccount $me */
        $me = Filament::auth()->user();

        return parent::getEloquentQuery()
            ->with(['loginAccount', 'device'])
            ->when(! $me->hasPermission('session.view_all'), fn (Builder $query) => $query->where('login_account_id', $me->id));
    }

    public static function isOpen(AuthSession $s): bool
    {
        return $s->revoked_at === null && $s->expires_at->isFuture();
    }

    public static function table(Table $table): Table
    {
        $current = app(ActorContext::class)->session()?->id;
        $pkt = fn ($d) => $d?->timezone('Asia/Karachi')->format('d M Y H:i');

        return $table
            ->columns([
                TextColumn::make('loginAccount.username')->label('User')->searchable(),
                TextColumn::make('browser')->label('Device')
                    ->state(fn (AuthSession $r) => trim(($r->browser ?? ucfirst($r->channel)).($r->os !== null ? ' on '.$r->os : '')))
                    ->description(fn (AuthSession $r) => $r->id === $current ? 'This session' : ($r->device?->isTrusted() ? 'Approved device' : null)),
                TextColumn::make('sign_in_method')->label('By')->badge()->placeholder('password')
                    ->color(fn (?string $state) => $state === 'passkey' ? 'success' : 'gray'),
                TextColumn::make('ip')->label('IP')->searchable(),
                TextColumn::make('city')->label('Location (area, city, region, country)')->wrap()->searchable(['area', 'city', 'region', 'country_name', 'ip_city'])
                    ->state(fn (AuthSession $r) => $r->placeLabel()
                        ?? ($r->location_source === 'browser' ? sprintf('%.5F, %.5F', $r->latitude, $r->longitude) : 'Unknown'))
                    ->icon(fn (AuthSession $r) => $r->mapUrl() !== null ? 'heroicon-o-map-pin' : null)
                    ->description(fn (AuthSession $r) => implode(' · ', array_filter([
                        $r->location_source === 'browser'
                            ? 'Exact (device GPS / Wi-Fi)'.($r->location_accuracy_m !== null ? ' ±'.number_format($r->location_accuracy_m).' m' : '')
                                .($r->location_at !== null ? ', '.$r->location_at->timezone('Asia/Karachi')->format('d M H:i') : '')
                            : ($r->location_source === 'ip' ? 'Rough, from the IP address — the person did not share the exact position' : null),
                        $r->location_source === 'browser' && $r->ipPlaceLabel() !== null ? 'IP: '.$r->ipPlaceLabel() : null,
                    ])) ?: null)
                    ->tooltip(fn (AuthSession $r) => $r->address)
                    ->url(fn (AuthSession $r) => $r->mapUrl(), shouldOpenInNewTab: true),
                TextColumn::make('address')->label('Full address')->wrap()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latitude')->label('Coordinates')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (AuthSession $r) => $r->latitude !== null ? sprintf('%.6F, %.6F', $r->latitude, $r->longitude) : null)->placeholder('—'),
                TextColumn::make('created_at')->label('Signed in')->formatStateUsing(fn ($state, AuthSession $r) => $pkt($r->created_at))->sortable(),
                TextColumn::make('last_seen_at')->label('Last active')->formatStateUsing(fn ($state, AuthSession $r) => $pkt($r->last_seen_at))->sortable(),
                TextColumn::make('status')->badge()
                    ->state(fn (AuthSession $r) => match (true) {
                        self::isOpen($r) => 'open', $r->revoked_at !== null => 'signed out', default => 'expired',
                    })
                    ->color(fn (string $state) => $state === 'open' ? 'success' : 'gray')
                    ->description(fn (AuthSession $r) => $r->revoked_reason !== null ? str_replace('_', ' ', $r->revoked_reason) : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('state')->label('Show')->default('open')
                    ->options(['open' => 'Open sessions', 'all' => 'All, including ended (history)'])
                    ->query(fn (Builder $query, array $data) => ($data['value'] ?? 'open') === 'open'
                        ? $query->whereNull('revoked_at')->where('expires_at', '>', now()) : $query),
                SelectFilter::make('sign_in_method')->label('Signed in by')->options(['password' => 'Password', 'passkey' => 'Passkey']),
            ])
            ->actions([
                Action::make('revoke')->label('Sign out')->icon('heroicon-o-arrow-right-start-on-rectangle')->color('danger')
                    ->visible(fn (AuthSession $r) => $r->id !== $current && self::isOpen($r) && Gate::allows('revoke', $r))
                    ->requiresConfirmation()
                    ->action(function (AuthSession $r): void {
                        /** @var LoginAccount $me */
                        $me = Filament::auth()->user();
                        app(SessionService::class)->revoke($r, $r->login_account_id === $me->id ? 'revoked_by_user' : 'revoked_by_admin');
                    }),
            ])
            ->bulkActions([])
            ->emptyStateDescription(app(GeoIp::class)->available() ? null : 'Locations from IP addresses appear once the location database is installed (php artisan geoip:update).');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuthSessions::route('/')];
    }
}
