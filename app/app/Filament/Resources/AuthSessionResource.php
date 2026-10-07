<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Auth\ActorContext;
use App\Domain\Auth\SessionService;
use App\Filament\Resources\AuthSessionResource\Pages;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** Active sign-ins. Everyone sees their own; `session.view_all` sees the entity's. */
class AuthSessionResource extends Resource
{
    protected static ?string $model = AuthSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'Sessions';

    protected static ?string $slug = 'sessions';

    protected static ?int $navigationSort = 30;

    /** @return Builder<AuthSession> */
    public static function getEloquentQuery(): Builder
    {
        /** @var LoginAccount $me */
        $me = Filament::auth()->user();

        return parent::getEloquentQuery()
            ->with(['loginAccount', 'device'])
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->when(! $me->hasPermission('session.view_all'), fn (Builder $q) => $q->where('login_account_id', $me->id));
    }

    public static function table(Table $table): Table
    {
        $current = app(ActorContext::class)->session()?->id;

        return $table
            ->columns([
                TextColumn::make('loginAccount.username')->label('User'),
                TextColumn::make('channel')->badge(),
                TextColumn::make('device.name')->label('Device')->limit(40)->placeholder('—')
                    ->description(fn (AuthSession $r) => $r->id === $current ? 'This session' : null),
                TextColumn::make('ip')->label('IP'),
                TextColumn::make('created_at')->label('Signed in')->dateTime()->sortable(),
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
                TextColumn::make('expires_at')->dateTime(),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->actions([
                Action::make('revoke')->label('Sign out')->icon('heroicon-o-arrow-right-start-on-rectangle')->color('danger')
                    ->visible(fn (AuthSession $r) => $r->id !== $current && Gate::allows('revoke', $r))
                    ->requiresConfirmation()
                    ->action(function (AuthSession $r): void {
                        /** @var LoginAccount $me */
                        $me = Filament::auth()->user();
                        app(SessionService::class)->revoke($r, $r->login_account_id === $me->id ? 'revoked_by_user' : 'revoked_by_admin');
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuthSessions::route('/')];
    }
}
