<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\RequisitionResource\Pages;

use App\Domain\Inventory\RequisitionService;
use App\Filament\Resources\Inventory\RequisitionResource;
use App\Filament\Support\PrintButton;
use App\Filament\Support\RunsDomainActions;
use App\Models\Inventory\Requisition;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

/** requested → approved (by someone else) → issued; the service enforces every rule. */
class ViewRequisition extends ViewRecord
{
    use RunsDomainActions;

    protected static string $resource = RequisitionResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;
        $service = fn () => app(RequisitionService::class);

        return [
            PrintButton::header('requisition'),
            Action::make('approve')->color('success')->icon('heroicon-o-check')
                ->visible(fn (Requisition $r) => $r->status === Requisition::REQUESTED && $can('requisition.approve'))
                ->form([Textarea::make('note')->maxLength(1000)])
                ->action(fn (Requisition $r, array $data) => $this->runDomain(fn () => $service()->approve($r, $data['note'] ?? null), 'Approved')),
            Action::make('reject')->color('danger')->icon('heroicon-o-x-mark')
                ->visible(fn (Requisition $r) => $r->status === Requisition::REQUESTED && $can('requisition.approve'))
                ->form([Textarea::make('note')->required()->maxLength(1000)])
                ->action(fn (Requisition $r, array $data) => $this->runDomain(fn () => $service()->reject($r, $data['note']), 'Rejected')),
            Action::make('issue')->label('Issue stock')->color('primary')->icon('heroicon-o-arrow-up-on-square')
                ->visible(fn (Requisition $r) => $r->status === Requisition::APPROVED && $can('requisition.create'))
                ->requiresConfirmation()
                ->modalDescription('Moves the stock now. Refused if the godown does not hold enough.')
                ->action(fn (Requisition $r) => $this->runDomain(fn () => $service()->issue($r), 'Stock issued')),
            Action::make('cancel')->color('gray')
                ->visible(fn (Requisition $r) => in_array($r->status, [Requisition::REQUESTED, Requisition::APPROVED], true))
                ->form([Textarea::make('note')->label('Reason')->maxLength(1000)])
                ->action(fn (Requisition $r, array $data) => $this->runDomain(fn () => $service()->cancel($r, $data['note'] ?? null), 'Cancelled')),
        ];
    }
}
