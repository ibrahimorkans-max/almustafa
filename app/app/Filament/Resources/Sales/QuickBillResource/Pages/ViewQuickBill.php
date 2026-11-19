<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\QuickBillResource\Pages;

use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Sales\QuickBills;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Sales\QuickBillResource;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Sales\QuickBill;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewQuickBill extends ViewRecord
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = QuickBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PrintButton::header('quick_bill'),
            Action::make('cancel')->label(__('Cancel this bill'))->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (QuickBill $r) => $r->status === QuickBill::POSTED && (Filament::auth()->user()?->can('voucher.reverse') ?? false))
                ->requiresConfirmation()
                ->modalDescription(__('Its voucher and the money received are reversed, the stock goes back and the sauda gets its weight back. This cannot be undone.'))
                ->form([Textarea::make('reason')->label(__('Why is it cancelled?'))->required()->minLength(5)->maxLength(500)])
                ->action(function (QuickBill $r, array $data): void {
                    try {
                        app(QuickBills::class)->cancel($r, (string) $data['reason']);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title(__($e->getMessage()))->danger()->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title(__('Bill cancelled.'))->send();
                    $this->refreshFormData([]);
                }),
            // More money later: any mode, any amount, as many times as needed.
            Action::make('settle')->icon('heroicon-o-banknotes')->color('success')
                ->label(fn (QuickBill $r) => $r->net_paise >= 0 ? __('Receive payment') : __('Pay the party'))
                ->visible(fn (QuickBill $r) => $r->status === QuickBill::POSTED && $r->balancePaise() > 0 && QuickBills::canCreate())
                ->form(fn (QuickBill $r) => [
                    TextInput::make('amount')->label(__('Amount (Rs)'))->required()->regex(Units::DECIMAL_2)->prefix('Rs')
                        ->default(\App\Support\Values\Money::paise($r->balancePaise())->toDecimal())
                        ->helperText(__('Left to pay: :amount', ['amount' => Units::paiseToRupees($r->balancePaise())])),
                    Select::make('mode')->label(__('How'))->required()->default('cash')->options(\App\Support\Locales::options(QuickBill::PAYMENT_MODES)),
                    Select::make('ledger_id')->label(__('Into / from'))->required()->options(fn () => QuickBillResource::cashBankOptions())
                        ->default(fn () => LedgerAccount::query()->where('system_key', 'cash')->value('id')),
                    TextInput::make('reference')->label(__('Cheque / transaction no.'))->maxLength(60),
                    DateTimePicker::make('paid_at')->label(__('Date and time'))->seconds(false)->default(now('Asia/Karachi'))->timezone('Asia/Karachi'),
                ])
                ->action(function (QuickBill $r, array $data): void {
                    try {
                        app(QuickBills::class)->settlePayment($r, $data);
                    } catch (BusinessRuleViolation|ApprovalDenied $e) {
                        Notification::make()->title(__($e->getMessage()))->danger()->send();

                        return;
                    }
                    Notification::make()->success()->title($r->net_paise >= 0 ? __('Payment received.') : __('Payment requested: it is paid once approved.'))->send();
                    $this->refreshFormData([]);
                }),
        ];
    }
}
