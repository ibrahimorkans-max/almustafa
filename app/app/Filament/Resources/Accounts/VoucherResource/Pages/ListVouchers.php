<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\VoucherResource\Pages;

use App\Domain\Accounts\ManualVouchers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\VoucherResource;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Voucher;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
        $amount = fn (string $name = 'amount') => TextInput::make($name)->label('Amount (₹)')->required()->regex(Units::DECIMAL_2);
        $date = fn () => DatePicker::make('date')->default(now())->required();

        return [
            ActionGroup::make([
                Action::make('receipt')->label('Receipt from party')->icon('heroicon-o-arrow-down-left')
                    ->form([
                        Select::make('party_id')->label('From party')->required()->searchable()->options(fn () => Party::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('into')->label('Into')->required()->options($moneyLedgers),
                        $amount(), $date(), Textarea::make('narration')->maxLength(500),
                    ])
                    ->action(fn (array $data) => $this->postVoucher(fn (ManualVouchers $m) => $m->receipt(
                        Party::query()->findOrFail($data['party_id']), LedgerAccount::query()->findOrFail($data['into']),
                        Units::rupeesToPaise($data['amount']), CarbonImmutable::parse($data['date'], 'Asia/Kolkata'), $data['narration'] ?? null,
                    ))),
                Action::make('contra')->label('Contra (cash ↔ bank)')->icon('heroicon-o-arrows-right-left')
                    ->form([
                        Select::make('from')->required()->options($moneyLedgers),
                        Select::make('to')->required()->options($moneyLedgers),
                        $amount(), $date(), Textarea::make('narration')->maxLength(500),
                    ])
                    ->action(fn (array $data) => $this->postVoucher(fn (ManualVouchers $m) => $m->contra(
                        LedgerAccount::query()->findOrFail($data['from']), LedgerAccount::query()->findOrFail($data['to']),
                        Units::rupeesToPaise($data['amount']), CarbonImmutable::parse($data['date'], 'Asia/Kolkata'), $data['narration'] ?? null,
                    ))),
                Action::make('journal')->label('Journal')->icon('heroicon-o-pencil-square')
                    ->modalDescription('Adjustments between ledgers. Bank and cash cannot be used here.')
                    ->form([
                        $date(),
                        Repeater::make('lines')->required()->minItems(2)->defaultItems(2)->columns(3)->schema([
                            Select::make('account')->label('Ledger')->required()->searchable()->options($otherLedgers),
                            TextInput::make('dr')->label('Debit (₹)')->regex(Units::DECIMAL_2),
                            TextInput::make('cr')->label('Credit (₹)')->regex(Units::DECIMAL_2),
                        ]),
                        Textarea::make('narration')->required()->minLength(5)->maxLength(500),
                    ])
                    ->action(fn (array $data) => $this->postVoucher(fn (ManualVouchers $m) => $m->journal(
                        CarbonImmutable::parse($data['date'], 'Asia/Kolkata'),
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
