<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Auth\DeviceService;
use App\Filament\Resources\DeviceResource\Pages;
use App\Models\Device;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationGroup = 'Security';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('loginAccount.username')->label('User')->searchable(),
                TextColumn::make('name')->limit(40)->placeholder('—'),
                TextColumn::make('platform')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    Device::STATUS_ACTIVE => 'success',
                    Device::STATUS_WIPE_PENDING => 'warning',
                    default => 'danger',
                }),
                IconColumn::make('trusted_at')->label('Trusted')->boolean()->getStateUsing(fn (Device $r) => $r->isTrusted()),
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
                TextColumn::make('last_ip')->label('Last IP'),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active', 'revoked' => 'Revoked', 'wipe_pending' => 'Wipe pending', 'wiped' => 'Wiped',
                ]),
            ])
            ->actions([
                Action::make('lost')->label('Mark lost / stolen')->icon('heroicon-o-exclamation-triangle')->color('danger')
                    ->visible(fn (Device $r) => $r->reported_lost_at === null && $r->status !== Device::STATUS_WIPED && Gate::allows('manage', $r))
                    ->form([
                        Textarea::make('reason')->label('What happened')->required()->minLength(5)->maxLength(500),
                        Toggle::make('sign_out_everywhere')->label('Also sign this user out on all their other devices')->default(true),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription("Phones, PWA and gate PCs erase their local data at their next connection and can do nothing else until then; a browser is signed out. Owners are alerted. Consider resetting the user's password too.")
                    ->action(function (Device $r, array $data): void {
                        app(DeviceService::class)->markLost($r, $data['reason'], (bool) $data['sign_out_everywhere']);
                        Notification::make()->title('Device marked lost. Wipe queued.')->success()->send();
                    }),
                Action::make('revoke')->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (Device $r) => $r->isUsable() && Gate::allows('manage', $r))
                    ->form([Textarea::make('reason')->required()->maxLength(500)])
                    ->requiresConfirmation()
                    ->modalDescription('The device is signed out everywhere and can no longer sign in.')
                    ->action(fn (Device $r, array $data) => app(DeviceService::class)->revoke($r, $data['reason'])),
                Action::make('wipe')->label('Remote wipe')->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn (Device $r) => in_array($r->platform, ['android', 'ios', 'pwa', 'agent'], true)
                        && $r->status === Device::STATUS_ACTIVE && Gate::allows('manage', $r))
                    ->form([Textarea::make('reason')->required()->maxLength(500)])
                    ->requiresConfirmation()
                    ->modalDescription('The device deletes its local data at its next sync and is signed out.')
                    ->action(fn (Device $r, array $data) => app(DeviceService::class)->requestWipe($r, $data['reason'])),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDevices::route('/')];
    }
}
