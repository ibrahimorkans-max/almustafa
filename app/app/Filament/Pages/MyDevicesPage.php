<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Auth\ActorContext;
use App\Domain\Auth\DeviceService;
use App\Domain\Settings\SettingsService;
use App\Models\Device;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Security → My devices: the phones, computers and installed app (PWA) this person signs in from,
 * each with its registration id, last activity and whether it is approved. A person removes a device
 * they no longer use (it is signed out and cannot sign in again); the mill's limit is shown. Also shows
 * whether this browser may share its location.
 */
class MyDevicesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'My devices';

    protected static ?string $title = 'My devices';

    protected static ?string $slug = 'my-devices';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.my-devices';

    /** In the user menu (with Passkeys and Two-factor), not the sidebar: some roles have only their own desk there. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return Filament::auth()->user() instanceof LoginAccount;
    }

    /** "DEV-1A2B-3C4D": the registration id people can read out to the Super Admin. */
    public static function registrationId(Device $device): string
    {
        $hex = strtoupper(substr(hash('sha256', $device->device_uid), 0, 8));

        return 'DEV-'.substr($hex, 0, 4).'-'.substr($hex, 4, 4);
    }

    public function currentDeviceId(): ?string
    {
        return app(ActorContext::class)->session()?->device_id;
    }

    /** @return array{used: int, limit: int} */
    public function getLimit(): array
    {
        /** @var LoginAccount $me */
        $me = Filament::auth()->user();

        return ['used' => DeviceService::devicesInUse($me), 'limit' => (int) app(SettingsService::class)->get(SettingsService::MAX_DEVICES, $me->entity_id)];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Device::query()->where('login_account_id', Filament::auth()->id())->where('platform', '!=', 'agent'))
            ->columns([
                TextColumn::make('registration')->label('Registration ID')->fontFamily('mono')
                    ->state(fn (Device $r) => self::registrationId($r))
                    ->description(fn (Device $r) => $r->id === $this->currentDeviceId() ? 'This device' : null),
                TextColumn::make('name')->label('Device')->limit(50)->placeholder('—')->tooltip(fn (Device $r) => $r->name),
                TextColumn::make('platform')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    'pwa' => 'Installed app', 'web' => 'Browser', 'android' => 'Android app', 'ios' => 'iPhone app', default => $state,
                }),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === Device::STATUS_ACTIVE ? 'success' : 'danger')
                    ->description(fn (Device $r) => $r->status === Device::STATUS_ACTIVE ? ($r->isTrusted() ? 'Approved' : 'Not approved yet') : null),
                TextColumn::make('created_at')->label('Registered')->date('d M Y', 'Asia/Karachi'),
                TextColumn::make('last_seen_at')->label('Last activity')->since()->placeholder('—')
                    ->tooltip(fn (Device $r) => $r->last_seen_at?->timezone('Asia/Karachi')->format('d M Y H:i').($r->last_ip ? ' · '.$r->last_ip : '')),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->actions([
                Action::make('remove')->label('Remove')->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (Device $r) => $r->isUsable() && $r->id !== $this->currentDeviceId())
                    ->requiresConfirmation()
                    ->modalDescription('It is signed out and cannot sign in again with this account. Use it when a phone is replaced or a computer is no longer yours.')
                    ->action(function (Device $record): void {
                        abort_unless($record->login_account_id === Filament::auth()->id(), 403);
                        app(DeviceService::class)->revoke($record, 'Removed by its user under My devices');
                        Notification::make()->success()->title('Device removed.')->send();
                    }),
            ])
            ->paginated(false);
    }
}
