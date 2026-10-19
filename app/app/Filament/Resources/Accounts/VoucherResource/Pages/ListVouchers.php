<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\VoucherResource\Pages;

use App\Domain\Accounts\ManualVouchers;
use App\Domain\Sales\SalesBillService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\VoucherResource;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Voucher;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/** Manual entries. Payments to parties are not here: they go through payment requests and approval. */
class ListVouchers extends ListRecords
{
    protected static string $resource = VoucherResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn () => Filament::auth()->user()?->can('ledger.post') ?? false;
        $moneyLedgers = fn () => LedgerAccount::query()->whereIn('kind', ['bank', 'cash'])->where('status', 'active')->orderBy('name')->get()
            ->mapWithKeys(fn (LedgerAccount $l) => [$l->id => $l->displayName()]);
        $otherLedgers = fn () => LedgerAccount::query()->whereNotIn('kind', ['bank', 'cash'])->where('status', 'active')->orderBy('name')->pluck('name', 'id');
        $amount = fn (string $name = 'amount') => TextInput::make($name)->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2);
        $date = fn () => DatePicker::make('date')->default(now())->required();

        return [
            ActionGroup::make([
                Action::make('receipt')->label('Receipt from party')->icon('heroicon-o-arrow-down-left')
                    ->form([
                        Select::make('party_id')->label('From party')->required()->searchable()->live()
                            ->options(fn () => Party::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('into')->label('Into')->required()->options($moneyLedgers),
                        $amount(), $date(),
                        CheckboxList::make('invoices')->label('Against invoices (settled oldest first; any remainder is on account)')
                            ->options(fn (Get $get) => self::openInvoices($get('party_id'))),
                        Textarea::make('narration')->maxLength(500),
                    ])
                    ->action(fn (array $data) => $this->postVoucher(fn (ManualVouchers $m) => $m->receipt(
                        Party::query()->findOrFail($data['party_id']), LedgerAccount::query()->findOrFail($data['into']),
                        Units::rupeesToPaise($data['amount']), CarbonImmutable::parse($data['date'], 'Asia/Karachi'), $data['narration'] ?? null,
                        self::allocate(Units::rupeesToPaise($data['amount']), (array) ($data['invoices'] ?? [])),
                    ))),
                Action::make('contra')->label('Contra (cash ↔ bank)')->icon('heroicon-o-arrows-right-left')
                    ->form([
                        Select::make('from')->required()->options($moneyLedgers),
                        Select::make('to')->required()->options($moneyLedgers),
                        $amount(), $date(), Textarea::make('narration')->maxLength(500),
                    ])
                    ->action(fn (array $data) => $this->postVoucher(fn (ManualVouchers $m) => $m->contra(
                        LedgerAccount::query()->findOrFail($data['from']), LedgerAccount::query()->findOrFail($data['to']),
                        Units::rupeesToPaise($data['amount']), CarbonImmutable::parse($data['date'], 'Asia/Karachi'), $data['narration'] ?? null,
                    ))),
                Action::make('journal')->label('Journal')->icon('heroicon-o-pencil-square')
                    ->modalDescription('Adjustments between ledgers. Bank and cash cannot be used here.')
                    ->form([
                        $date(),
                        Repeater::make('lines')->required()->minItems(2)->defaultItems(2)->columns(3)->schema([
                            Select::make('account')->label('Ledger')->required()->searchable()->options($otherLedgers),
                            TextInput::make('dr')->label('Debit (Rs)')->regex(Units::DECIMAL_2),
                            TextInput::make('cr')->label('Credit (Rs)')->regex(Units::DECIMAL_2),
                        ]),
                        Textarea::make('narration')->required()->minLength(5)->maxLength(500),
                    ])
                    ->action(fn (array $data) => $this->postVoucher(fn (ManualVouchers $m) => $m->journal(
                        CarbonImmutable::parse($data['date'], 'Asia/Karachi'),
                        array_map(fn (array $l) => [
                            'account' => LedgerAccount::query()->findOrFail($l['account']),
                            'dr' => filled($l['dr'] ?? null) ? Units::rupeesToPaise($l['dr']) : 0,
                            'cr' => filled($l['cr'] ?? null) ? Units::rupeesToPaise($l['cr']) : 0,
                        ], array_values($data['lines'])),
                        $data['narration'],
                    ))),
            ])->label('New entry')->icon('heroicon-o-plus')->button()->visible($can),
        ];
    }

    /** @return array<string, string> posted invoices of the party with something outstanding, oldest due first */
    public static function openInvoices(?string $partyId): array
    {
        if ($partyId === null) {
            return [];
        }
        $service = app(SalesBillService::class);

        return SalesBill::query()->where('party_id', $partyId)->where('status', SalesBill::POSTED)->orderBy('due_date')->get()
            ->filter(fn (SalesBill $b) => $service->outstanding($b) > 0)
            ->mapWithKeys(fn (SalesBill $b) => [$b->id => sprintf('%s · due %s · %s outstanding', $b->number, $b->due_date->format('d M'), Units::paiseToRupees($service->outstanding($b)))])
            ->all();
    }

    /**
     * Settles the chosen invoices oldest-due first, up to the amount received.
     *
     * @param  list<string>  $invoiceIds
     * @return array<string, int>
     */
    public static function allocate(int $amount, array $invoiceIds): array
    {
        $service = app(SalesBillService::class);
        $allocations = [];
        foreach (SalesBill::query()->whereIn('id', $invoiceIds)->orderBy('due_date')->get() as $bill) {
            if ($amount <= 0) {
                break;
            }
            $take = min($amount, $service->outstanding($bill));
            if ($take > 0) {
                $allocations[$bill->id] = $take;
                $amount -= $take;
            }
        }

        return $allocations;
    }

    /** @param  callable(ManualVouchers): Voucher  $callback */
    private function postVoucher(callable $callback): void
    {
        try {
            $voucher = $callback(app(ManualVouchers::class));
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title("Posted {$voucher->number}")->success()->send();
    }
}
