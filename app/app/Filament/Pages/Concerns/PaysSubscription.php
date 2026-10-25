<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Domain\Saas\Billing;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Support\Units;
use App\Models\Customer;
use App\Models\LoginAccount;
use App\Models\SaasInvoice;
use App\Models\SaasPaymentMethod;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** A customer's invoices and "I've paid" (My subscription, and the payment page). */
trait PaysSubscription
{
    public function customer(): ?Customer
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount ? Customer::query()->find($user->customerId()) : null;
    }

    /** @return Collection<int, SaasInvoice> */
    public function invoices(bool $openOnly = false): Collection
    {
        $customer = $this->customer();

        return $customer === null ? new Collection : SaasInvoice::query()->where('customer_id', $customer->id)
            ->when($openOnly, fn ($q) => $q->where('status', SaasInvoice::OPEN), fn ($q) => $q->where('status', '!=', SaasInvoice::VOID))
            ->orderByDesc('issued_at')->limit(24)->get();
    }

    public function canReportPayment(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isOwner();
    }

    public function reportPaymentAction(): Action
    {
        return Action::make('reportPayment')->icon('heroicon-o-banknotes')->size('sm')
            ->label(fn (array $arguments) => SaasInvoice::query()->whereKey($arguments['invoice'] ?? null)->whereNotNull('reported_at')->exists()
                ? 'Correct the transaction' : 'I\'ve paid')
            ->visible(fn () => $this->canReportPayment())
            ->modalHeading(fn (array $arguments) => 'Report a payment: '.(SaasInvoice::query()->find($arguments['invoice'] ?? null)->number ?? ''))
            ->modalDescription('After you pay, enter the transaction ID exactly as it appears on your bank or wallet receipt. We match it with the payment we received, then mark the invoice paid.')
            ->fillForm(fn (array $arguments) => [
                'amount' => number_format((SaasInvoice::query()->find($arguments['invoice'] ?? null)->amount_paise ?? 0) / 100, 2, '.', ''),
                'paid_on' => now('Asia/Karachi')->toDateString(),
                'method_id' => SaasPaymentMethod::query()->offered()->count() === 1 ? SaasPaymentMethod::query()->offered()->value('id') : null,
            ])
            ->form([
                Select::make('method_id')->label('Paid with')->native(false)
                    ->options(fn () => SaasPaymentMethod::query()->offered()->pluck('name', 'id')->all())
                    ->required(fn () => SaasPaymentMethod::query()->offered()->exists())
                    ->visible(fn () => SaasPaymentMethod::query()->offered()->exists()),
                Grid::make(2)->schema([
                    TextInput::make('transaction_id')->label('Transaction ID')->required()->maxLength(60)
                        ->placeholder('e.g. FT26112233445 or 0123456789')
                        ->helperText('Letters and numbers as printed on the receipt; spaces are removed.')
                        ->extraInputAttributes(['autocomplete' => 'off', 'spellcheck' => 'false', 'style' => 'text-transform: uppercase; font-family: monospace;']),
                    TextInput::make('transaction_id_again')->label('Transaction ID again')->required()->maxLength(60)
                        ->extraInputAttributes(['autocomplete' => 'off', 'spellcheck' => 'false', 'style' => 'text-transform: uppercase; font-family: monospace;'])
                        ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            if (SaasInvoice::cleanTransactionId((string) $value) !== SaasInvoice::cleanTransactionId((string) $get('transaction_id'))) {
                                $fail('The two transaction IDs do not match. Check them on your receipt.');
                            }
                        }),
                    TextInput::make('amount')->label('Amount sent (Rs)')->required()->regex(Units::DECIMAL_2),
                    DatePicker::make('paid_on')->label('Paid on')->required()->maxDate(now('Asia/Karachi')),
                ]),
                FileUpload::make('receipt')->label('Receipt or screenshot (photo or PDF, optional)')->storeFiles(false)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(5120),
                Textarea::make('note')->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $customer = $this->customer();
                $invoice = $customer === null ? null
                    : SaasInvoice::query()->where('customer_id', $customer->id)->find($arguments['invoice'] ?? null);
                $user = Filament::auth()->user();
                abort_if($invoice === null || ! $user instanceof LoginAccount, 404);
                $file = $data['receipt'] ?? null;
                $file = is_array($file) ? (reset($file) ?: null) : $file;
                try {
                    $path = $file instanceof TemporaryUploadedFile ? $file->store('saas-receipts', 'local') : null;
                    app(Billing::class)->reportPayment($invoice, $user, (string) $data['transaction_id'], (string) $data['paid_on'],
                        $data['note'] ?? null, is_string($path) ? $path : null, $data['method_id'] ?? null, Units::rupeesToPaise((string) $data['amount']));
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();   // the form stays open with what was typed

                    return;
                }
                Notification::make()->success()->title('Thank you. We\'ll mark the invoice paid once the payment reaches us.')->send();
            });
    }
}
