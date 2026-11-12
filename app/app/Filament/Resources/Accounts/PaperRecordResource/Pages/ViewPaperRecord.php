<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\PaperRecordResource\Pages;

use App\Domain\Accounts\PaperRecords;
use App\Filament\Resources\Accounts\PaperRecordResource;
use App\Filament\Resources\Accounts\VoucherResource;
use App\Filament\Support\AttachmentsPanel;
use App\Filament\Support\RunsDomainActions;
use App\Models\Accounts\PaperRecord;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewPaperRecord extends ViewRecord
{
    use RunsDomainActions;

    protected static string $resource = PaperRecordResource::class;

    protected function getHeaderActions(): array
    {
        $me = Filament::auth()->user();

        return [
            AttachmentsPanel::action()->label('Attach page photo'),
            Action::make('voucher')->label('Open voucher')->icon('heroicon-o-document-text')->color('gray')
                ->visible(fn (PaperRecord $r) => $r->voucher_id !== null && VoucherResource::canViewAny())
                ->url(fn (PaperRecord $r) => VoucherResource::getUrl('view', ['record' => $r->voucher_id])),
            Action::make('cancel')->label('Cancel this record')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (PaperRecord $r) => ! $r->isCancelled() && ($me?->hasPermission('ledger.post') || $me?->hasPermission('stock.adjust')))
                ->requiresConfirmation()
                ->modalDescription('Its voucher and stock movement are reversed on the same date; the record stays, marked cancelled. Enter the right one again after.')
                ->form([Textarea::make('reason')->label('Why')->required()->minLength(5)->maxLength(300)])
                ->action(fn (PaperRecord $r, array $data) => $this->runDomain(fn () => app(PaperRecords::class)->cancel($r, $data['reason']), 'Record cancelled')),
        ];
    }
}
