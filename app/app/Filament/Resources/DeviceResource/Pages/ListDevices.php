<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Domain\Auth\DeviceService;
use App\Filament\Resources\DeviceResource;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registerAgent')->label('Register gate agent')->icon('heroicon-o-cpu-chip')
                ->visible(fn () => Filament::auth()->user()?->can('device.manage') ?? false)
                ->modalDescription('Creates a device for the weighbridge PC and shows its signing secret ONCE. Put it in the agent\'s config file; it cannot be shown again.')
                ->form([
                    Select::make('login_account_id')->label('Agent login account')->required()
                        ->options(fn () => LoginAccount::query()->with('role')->where('status', 'active')->get()
                            ->filter(fn (LoginAccount $a) => $a->role->code === 'GATE_OPERATOR')
                            ->mapWithKeys(fn (LoginAccount $a) => [$a->id => $a->username])),
                    TextInput::make('name')->label('PC name')->required()->default('Weighbridge PC')->maxLength(120),
                ])
                ->action(function (array $data): void {
                    $account = LoginAccount::query()->findOrFail($data['login_account_id']);
                    $uid = 'agent-'.Str::lower(Str::random(24));
                    ['secret' => $secret] = app(DeviceService::class)->registerAgent($account, $uid, $data['name']);

                    Notification::make()->title('Gate agent registered — copy these now')
                        ->body("device_uid: {$uid}\nsigning_secret: {$secret}")
                        ->persistent()->warning()->send();
                }),
        ];
    }
}
