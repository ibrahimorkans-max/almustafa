<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\DispatchOrderResource\Pages;

use App\Domain\Gate\GateService;
use App\Domain\Sales\DispatchService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\ApprovalRequestResource;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Resources\Sales\DispatchOrderResource;
use App\Filament\Support\RunsDomainActions;
use App\Models\Sales\DispatchOrder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewDispatchOrder extends ViewRecord
{
    use RunsDomainActions;

    protected static string $resource = DispatchOrderResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;

        return [
            Action::make('arrived')->label('Vehicle arrived')->icon('heroicon-o-truck')
                ->visible(fn (DispatchOrder $o) => $o->status === DispatchOrder::OPEN && $can('gate.entry'))
                ->form([
                    TextInput::make('registration_no')->label('Vehicle registration no.')->required()->maxLength(20),
                    TextInput::make('driver_name')->maxLength(120),
                ])
                ->action(function (DispatchOrder $o, array $data) {
                    try {
                        $entry = app(GateService::class)->arrive(['registration_no' => $data['registration_no'], 'driver_name' => $data['driver_name'] ?? null, 'dispatch_order_id' => $o->id]);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return null;
                    }

                    return redirect(GateEntryResource::getUrl('view', ['record' => $entry]));
                }),
            Action::make('approval')->label('Open credit approval')->icon('heroicon-o-shield-check')
                ->visible(fn (DispatchOrder $o) => $o->status === DispatchOrder::PENDING_CREDIT && $o->credit_override_request_id !== null)
                ->url(fn (DispatchOrder $o) => ApprovalRequestResource::getUrl('view', ['record' => $o->credit_override_request_id])),
            Action::make('gate')->label('Open gate entry')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->visible(fn (DispatchOrder $o) => $o->gate_entry_id !== null)
                ->url(fn (DispatchOrder $o) => GateEntryResource::getUrl('view', ['record' => $o->gate_entry_id])),
            Action::make('cancel')->color('danger')
                ->visible(fn (DispatchOrder $o) => in_array($o->status, [DispatchOrder::PENDING_CREDIT, DispatchOrder::OPEN], true) && $can('dispatch.manage'))
                ->form([Textarea::make('reason')->required()->maxLength(1000)])
                ->action(fn (DispatchOrder $o, array $data) => $this->runDomain(fn () => app(DispatchService::class)->cancel($o, $data['reason']), 'Order cancelled')),
        ];
    }
}
