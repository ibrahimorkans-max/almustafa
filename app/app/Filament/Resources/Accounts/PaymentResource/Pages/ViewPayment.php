<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\PaymentResource\Pages;

use App\Domain\Accounts\AccountsDocuments;
use App\Domain\Accounts\PaymentService;
use App\Filament\Resources\Accounts\PaymentResource;
use App\Filament\Resources\ApprovalRequestResource;
use App\Filament\Support\AttachmentsPanel;
use App\Filament\Support\PrintButton;
use App\Filament\Support\RequestsVoucherReversal;
use App\Filament\Support\RunsDomainActions;
use App\Models\Accounts\Payment;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    use RunsDomainActions;

    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;

        return [
            AttachmentsPanel::action(),
            Action::make('approval')->label('Open approval')->icon('heroicon-o-shield-check')
                ->visible(fn (Payment $p) => $p->isOpen() && $p->approval_request_id !== null)
                ->url(fn (Payment $p) => ApprovalRequestResource::getUrl('view', ['record' => $p->approval_request_id])),
            PrintButton::dialog('printVoucher', 'Print payment voucher', fn (Payment $p) => [app(AccountsDocuments::class)->paymentVoucherPdf($p), str_replace('/', '-', $p->number).'.pdf'])
                ->visible(fn (Payment $p) => $p->status === Payment::APPROVED),
            Action::make('voucher')->label('Download voucher (PDF)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (Payment $p) => $p->status === Payment::APPROVED)
                ->action(fn (Payment $p) => response()->streamDownload(
                    fn () => print app(AccountsDocuments::class)->paymentVoucherPdf($p),
                    str_replace('/', '-', $p->number).'.pdf',
                    ['Content-Type' => 'application/pdf'],
                )),
            Action::make('reference')->label('Record transaction ID / cheque no.')->icon('heroicon-o-hashtag')
                ->visible(fn (Payment $p) => $p->status === Payment::APPROVED && $can('payment.create'))
                ->form([TextInput::make('reference')->required()->maxLength(60)])
                ->action(fn (Payment $p, array $data) => $this->runDomain(fn () => app(PaymentService::class)->recordReference($p, $data['reference']), 'Reference saved')),
            RequestsVoucherReversal::action(fn () => $this->record->getAttribute('status') === Payment::APPROVED ? $this->record->getAttribute('voucher_id') : null),
        ];
    }
}
