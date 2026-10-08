<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesBillResource\Pages;

use App\Domain\Gst\EInvoiceService;
use App\Domain\Sales\SalesBillService;
use App\Domain\Sales\SalesDocuments;
use App\Filament\Resources\Sales\SalesBillResource;
use App\Filament\Support\RequestsVoucherReversal;
use App\Filament\Support\RunsDomainActions;
use App\Models\Sales\SalesBill;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                ->modalDescription('Assigns the GST invoice number and posts the sales voucher (buyer, sales, output GST). It cannot be edited afterwards.')
                ->form([Textarea::make('note')->maxLength(1000)])
                ->action(fn (SalesBill $b, array $data) => $this->runDomain(fn () => app(SalesBillService::class)->post($b, $data['note'] ?? null), 'Invoice posted')),
            Action::make('pdf')->label('Download invoice (PDF)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (SalesBill $b) => $b->status === SalesBill::POSTED)
                ->action(fn (SalesBill $b) => response()->streamDownload(
                    fn () => print app(SalesDocuments::class)->invoicePdf($b),
                    str_replace('/', '-', (string) $b->number).'.pdf',
                    ['Content-Type' => 'application/pdf'],
                )),
            Action::make('irn')->label('Generate e-invoice (IRN)')->icon('heroicon-o-qr-code')->color('primary')
                ->visible(fn (SalesBill $b) => $b->status === SalesBill::POSTED && $b->buyer_gstin !== null && $b->einvoice_status !== 'generated' && $can('ledger.post'))
                ->requiresConfirmation()
                ->modalDescription('Reports this invoice to the e-invoice system (IRP) and stores the IRN and signed QR.')
                ->action(fn (SalesBill $b) => $this->runDomain(fn () => app(EInvoiceService::class)->generate($b), 'IRN generated')),
            Action::make('ewb')->label('Generate e-way bill')->icon('heroicon-o-truck')->color('primary')
                ->visible(fn (SalesBill $b) => $b->einvoice_status === 'generated' && $b->eway_bill_no === null && $can('ledger.post'))
                ->form([TextInput::make('distance')->label('Distance to the buyer (km)')->required()->integer()->minValue(1)->maxValue(4000)])
                ->action(fn (SalesBill $b, array $data) => $this->runDomain(fn () => app(EInvoiceService::class)->ewayBill($b, (int) $data['distance']), 'E-way bill generated')),
            Action::make('cancelIrn')->label('Cancel IRN')->color('danger')
                ->visible(fn (SalesBill $b) => $b->einvoice_status === 'generated' && $can('ledger.post'))
                ->modalDescription('Possible only within 24 hours of the IRN and before an e-way bill. Later corrections are credit notes.')
                ->form([
                    Select::make('reason')->options(EInvoiceService::CANCEL_REASONS)->required(),
                    Textarea::make('remark')->required()->minLength(5)->maxLength(100),
                ])
                ->action(fn (SalesBill $b, array $data) => $this->runDomain(fn () => app(EInvoiceService::class)->cancel($b, (string) $data['reason'], $data['remark']), 'IRN cancelled')),
            Action::make('cancelDraft')->label('Cancel draft')->color('gray')
                ->visible(fn (SalesBill $b) => $b->status === SalesBill::DRAFT && $can('ledger.post'))
                ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                ->action(fn (SalesBill $b, array $data) => $this->runDomain(fn () => app(SalesBillService::class)->cancelDraft($b, $data['reason']), 'Draft cancelled')),
            RequestsVoucherReversal::action(fn () => $this->record->getAttribute('status') === SalesBill::POSTED ? $this->record->getAttribute('voucher_id') : null),
        ];
    }
}
