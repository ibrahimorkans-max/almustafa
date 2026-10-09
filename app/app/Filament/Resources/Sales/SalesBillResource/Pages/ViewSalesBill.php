<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesBillResource\Pages;

use App\Domain\Sales\SalesBillService;
use App\Domain\Sales\SalesDocuments;
use App\Filament\Resources\Sales\SalesBillResource;
use App\Filament\Support\RequestsVoucherReversal;
use App\Filament\Support\RunsDomainActions;
use App\Models\Sales\SalesBill;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesBill extends ViewRecord
{
    use RunsDomainActions;

    protected static string $resource = SalesBillResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;

        return [
            Action::make('post')->label('Post invoice')->icon('heroicon-o-check')->color('success')
                ->visible(fn (SalesBill $b) => $b->status === SalesBill::DRAFT && $can('ledger.post'))
                ->requiresConfirmation()
                ->modalDescription('Assigns the invoice number and posts the sales voucher (buyer, sales, output sales tax). It cannot be edited afterwards.')
                ->form([Textarea::make('note')->maxLength(1000)])
                ->action(fn (SalesBill $b, array $data) => $this->runDomain(fn () => app(SalesBillService::class)->post($b, $data['note'] ?? null), 'Invoice posted')),
            Action::make('pdf')->label('Download invoice (PDF)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (SalesBill $b) => $b->status === SalesBill::POSTED)
                ->action(fn (SalesBill $b) => response()->streamDownload(
                    fn () => print app(SalesDocuments::class)->invoicePdf($b),
                    str_replace('/', '-', (string) $b->number).'.pdf',
                    ['Content-Type' => 'application/pdf'],
                )),
            Action::make('cancelDraft')->label('Cancel draft')->color('gray')
                ->visible(fn (SalesBill $b) => $b->status === SalesBill::DRAFT && $can('ledger.post'))
                ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                ->action(fn (SalesBill $b, array $data) => $this->runDomain(fn () => app(SalesBillService::class)->cancelDraft($b, $data['reason']), 'Draft cancelled')),
            RequestsVoucherReversal::action(fn () => $this->record->getAttribute('status') === SalesBill::POSTED ? $this->record->getAttribute('voucher_id') : null),
        ];
    }
}
