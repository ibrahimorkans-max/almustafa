<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Gate\LabReport;
use App\Domain\Sales\SalesBillService;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\PurchaseBill;
use App\Models\Accounts\VoucherLine;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;
use App\Support\Values\Money;

/**
 * What a party sees of itself when it signs in: where its account stands, its vehicles (weights, item,
 * lab decision), its account entries with the running balance, and its bills and invoices with what is
 * still open. Nothing of any other party.
 */
final class PartyStatement
{
    /** The party a party login belongs to. */
    public static function partyOf(?LoginAccount $account): ?Party
    {
        if ($account === null || ! $account->hasPermission('portal.view_own')) {
            return null;
        }

        return Party::query()->where('login_account_id', $account->id)->first();
    }

    /**
     * @return array{balance: int, standing: string, vehicles: list<array<string, mixed>>, entries: list<array<string, mixed>>, bills: list<array<string, mixed>>}
     */
    public function for(Party $party, int $limit = 100): array
    {
        $ledger = LedgerAccount::query()->where('party_id', $party->id)->first();
        $balance = 0;
        $entries = [];
        if ($ledger !== null) {
            $lines = VoucherLine::query()->with('voucher')->where('ledger_account_id', $ledger->id)
                ->orderBy('voucher_date')->orderBy('voucher_id')->orderBy('line_no')->get();
            foreach ($lines as $l) {
                $balance += $l->dr_paise - $l->cr_paise;
                $entries[] = [
                    'date' => $l->voucher_date, 'number' => $l->voucher->number, 'what' => (string) ($l->memo ?: $l->voucher->narration),
                    'dr' => $l->dr_paise, 'cr' => $l->cr_paise, 'balance' => $balance,
                ];
            }
            $entries = array_reverse(array_slice($entries, -$limit));
        }

        $vehicles = GateEntry::query()->with('product')->where('party_id', $party->id)
            ->orderByDesc('arrived_at')->limit($limit)->get()
            ->map(fn (GateEntry $e) => [
                'date' => $e->arrived_at, 'token' => $e->token_serial !== null || $e->token_no !== null ? $e->tokenLabel() : null, 'number' => $e->number, 'vehicle' => $e->vehicleLabel(),
                'item' => $e->itemLabel(), 'direction' => $e->direction, 'status' => str_replace('_', ' ', $e->status),
                'gross' => $e->gross_kg, 'tare' => $e->tare_kg, 'net' => $e->net_kg, 'payable' => $e->payable_kg,
                'lab' => $e->direction === 'inbound' && $e->purpose !== 'other' ? LabReport::decisionLabel(LabReport::for($e)) : null,
            ])->all();

        $purchases = app(PurchaseBillService::class);
        $sales = app(SalesBillService::class);
        $bills = PurchaseBill::query()->where('party_id', $party->id)->whereNotNull('number')->orderByDesc('bill_date')->limit($limit)->get()
            ->map(fn (PurchaseBill $b) => ['kind' => 'Purchase bill', 'number' => $b->number, 'date' => $b->bill_date, 'due' => $b->due_date,
                'amount' => $b->net_paise, 'open' => $b->status === 'posted' ? $purchases->outstanding($b) : 0, 'status' => $b->status])
            ->concat(SalesBill::query()->where('party_id', $party->id)->whereNotNull('number')->orderByDesc('bill_date')->limit($limit)->get()
                ->map(fn (SalesBill $b) => ['kind' => 'Sales invoice', 'number' => $b->number, 'date' => $b->bill_date, 'due' => $b->due_date,
                    'amount' => $b->total_paise, 'open' => $b->status === 'posted' ? $sales->outstanding($b) : 0, 'status' => $b->status]))
            ->sortByDesc('date')->values()->all();

        return [
            'balance' => $balance,
            'standing' => match (true) {
                $balance < 0 => 'We owe you '.Money::paise(-$balance)->format(),
                $balance > 0 => 'You owe us '.Money::paise($balance)->format(),
                default => 'Your account is settled',
            },
            'vehicles' => $vehicles,
            'entries' => $entries,
            'bills' => $bills,
        ];
    }
}
