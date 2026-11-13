<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Domain\Access\RoleTemplates;
use App\Domain\Auth\DeviceService;
use App\Domain\Gate\AgentPairing;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\DeviceResource;
use App\Models\Gate\Scale;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pairAgent')->label('Pair a weighbridge PC')->icon('heroicon-o-qr-code')
                ->visible(fn () => Filament::auth()->user()?->can('device.manage') ?? false)
                ->modalHeading('Pair a weighbridge PC')->modalSubmitActionLabel('Make pairing code')
                ->modalDescription('Gives a one-time code. On the PC run the set-up (or: ricemill-agent pair), type the ERP address and this code: the PC receives its login, device and weighbridge by itself. Nothing else to copy.')
                ->form([
                    Select::make('scale_id')->label('Weighbridge')->required()
                        ->options(fn () => Scale::query()->orderBy('code')->get()->mapWithKeys(fn (Scale $s) => [$s->id => "{$s->code} - {$s->name}"]))
                        ->default(fn () => Scale::query()->count() === 1 ? Scale::query()->value('id') : null),
                    Select::make('login_account_id')->label('PC login')->placeholder('Make a new login just for this PC (recommended)')
                        ->options(fn () => LoginAccount::query()->with('role')->where('status', 'active')->get()
                            ->filter(fn (LoginAccount $a) => $a->role->code === RoleTemplates::GATE_OPERATOR)
                            ->mapWithKeys(fn (LoginAccount $a) => [$a->id => $a->username]))
                        ->helperText('Choosing an existing gate login gives it a new password that only the PC knows: do not choose a login a person signs in with.'),
                    TextInput::make('name')->label('PC name')->required()->default('Weighbridge PC')->maxLength(120),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $r = app(AgentPairing::class)->issue($data['scale_id'], $data['login_account_id'] ?? null, $data['name']);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->title('Pairing code: '.$r['code'])
                        ->body(new HtmlString('On the weighbridge PC, in the agent folder, run the set-up and type:<br>ERP address: <b>'.e(rtrim((string) config('app.url'), '/'))
                            .'</b><br>Pairing code: <b style="font-size:1.3em;letter-spacing:.1em">'.e($r['code']).'</b><br>It works once, until '.e($r['expires_at']->format('H:i'))
                            .'. PC login: '.e($r['username']).($r['new_login'] ? ' (new)' : '').'.'))
                        ->persistent()->success()->send();
                }),
            Action::make('registerAgent')->label('Register by hand')->color('gray')->icon('heroicon-o-cpu-chip')
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
