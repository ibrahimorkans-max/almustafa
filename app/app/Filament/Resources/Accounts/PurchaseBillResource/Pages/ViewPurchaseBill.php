<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\PurchaseBillResource\Pages;

use App\Domain\Accounts\AccountsDocuments;
use App\Domain\Accounts\PurchaseBillService;
use App\Filament\Resources\Accounts\PurchaseBillResource;
use App\Filament\Support\RequestsVoucherReversal;
use App\Filament\Support\RunsDomainActions;
use App\Models\Accounts\PurchaseBill;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseBill extends ViewRecord
{
    use RunsDomainActions;

    protected static string $resource = PurchaseBillResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;

        return [
            Action::make('post')->label('Post to books')->icon('heroicon-o-check')->color('success')
                ->visible(fn (PurchaseBill $b) => $b->status === PurchaseBill::DRAFT && $can('ledger.post'))
                ->requiresConfirmation()
                ->modalDescription('Posts the purchase voucher (party, rate cut, round-off, brokerage). It cannot be edited afterwards, only reversed.')
                ->form([Textarea::make('note')->maxLength(1000)])
                ->action(fn (PurchaseBill $b, array $data) => $this->runDomain(fn () => app(PurchaseBillService::class)->post($b, $data['note'] ?? null), 'Bill posted')),
            Action::make('pdf')->label('Print purchase bill (PDF)')->icon('heroicon-o-printer')->color('gray')
                ->visible(fn (PurchaseBill $b) => $b->status === PurchaseBill::POSTED)
                ->action(fn (PurchaseBill $b) => response()->streamDownload(
                    fn () => print app(AccountsDocuments::class)->purchaseBillPdf($b),
                    str_replace('/', '-', (string) $b->number).'.pdf',
                    ['Content-Type' => 'application/pdf'],
                )),
            Action::make('cancelDraft')->label('Cancel draft')->color('gray')
                ->visible(fn (PurchaseBill $b) => $b->status === PurchaseBill::DRAFT && $can('ledger.post'))
                ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                ->action(fn (PurchaseBill $b, array $data) => $this->runDomain(fn () => app(PurchaseBillService::class)->cancelDraft($b, $data['reason']), 'Draft cancelled')),
            RequestsVoucherReversal::action(fn () => $this->record->getAttribute('status') === PurchaseBill::POSTED ? $this->record->getAttribute('voucher_id') : null),
        ];
    }
}
