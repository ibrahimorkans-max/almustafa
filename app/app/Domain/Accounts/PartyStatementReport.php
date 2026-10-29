<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Hr\ContractorVehicles;
use App\Domain\Saas\DataVisibility;
use App\Domain\Sales\SalesBillService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\PurchaseBill;
use App\Models\Gate\GateEntry;
use App\Models\Hr\ContractorBill;
use App\Models\Hr\ContractorBillLine;
use App\Models\Hr\ContractorBillPayment;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A party's statement for a period, from the books: opening and closing balance from its ledger, every
 * ledger entry with the running balance, its vehicles (tokens) with weights, rates and the bill each went
 * on, its bills with what is paid and still open, and its payments and receipts. For a labour contractor,
 * the tokens its vehicles worked, their charges, its contractor bills and their payments.
 *
 * Filters: transaction type, product, vehicle, sauda and payment status. Views: summary, detailed,
 * vehicle-wise, sauda-wise, payments, contractor. Balances are debit-positive (Dr: the party owes the mill).
 */
final class PartyStatementReport
{
    public const VIEWS = ['detailed' => 'Detailed', 'summary' => 'Summary', 'vehicle' => 'Vehicle-wise', 'sauda' => 'Sauda-wise',
        'payments' => 'Payments / receipts', 'contractor' => 'Contractor (tokens, bills, payments)'];

    /** Ledger entries by voucher type. */
    public const TYPES = ['all' => 'All transactions', 'purchase' => 'Purchases', 'sales' => 'Sales', 'payment' => 'Payments made',
        'receipt' => 'Receipts', 'journal' => 'Journal / adjustments', 'notes' => 'Debit / credit notes'];

    public const PAYMENT_STATUSES = ['all' => 'Any', 'unpaid' => 'Unpaid', 'partial' => 'Partly paid', 'paid' => 'Paid'];

    public function __construct(
        private readonly AccountReports $reports,
        private readonly PurchaseBillService $purchases,
        private readonly SalesBillService $sales,
        private readonly ContractorVehicles $contractorVehicles,
    ) {}

    /**
     * @param  array{type?: string, product_id?: ?string, vehicle?: ?string, sauda_id?: ?string, payment_status?: string, view?: string}  $f
     * @return array<string, mixed>
     */
    public function build(Party $party, CarbonImmutable $from, CarbonImmutable $to, array $f = []): array
    {
        $from = CarbonImmutable::instance(DataVisibility::clampDate($from, 'accounts'));
        $type = $f['type'] ?? 'all';
        $payStatus = $f['payment_status'] ?? 'all';
        $vehicle = filled($f['vehicle'] ?? null) ? strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $f['vehicle'])) : null;

        // The books: opening, every entry with its running balance (over all entries), closing.
        $ledger = LedgerAccount::query()->where('party_id', $party->id)->first();
        $book = $ledger === null ? ['opening' => 0, 'closing' => 0, 'lines' => []] : $this->reports->ledgerStatement($ledger, $from, $to);
        $wanted = match ($type) {
            'all' => null, 'notes' => ['debit_note', 'credit_note'], default => [$type],
        };
        $lines = array_values(array_filter($book['lines'], fn (array $l) => $wanted === null || in_array($l['type'], $wanted, true)));
        $payments = array_values(array_filter($book['lines'], fn (array $l) => in_array($l['type'], ['payment', 'receipt'], true)));

        // The party's vehicles in the period.
        $tokens = GateEntry::query()->with(['product', 'sauda'])->where('party_id', $party->id)->where('status', '!=', 'cancelled')
            ->where('arrived_at', '>=', $from->startOfDay()->utc())->where('arrived_at', '<=', $to->endOfDay()->utc())
            ->when(filled($f['product_id'] ?? null), fn ($q) => $q->where('product_id', $f['product_id']))
            ->when(filled($f['sauda_id'] ?? null), fn ($q) => $q->where('sauda_id', $f['sauda_id']))
            ->when($vehicle !== null, fn ($q) => $q->where('registration_no', 'ilike', "%{$vehicle}%"))
            ->orderBy('arrived_at')->get();
        $billsByEntry = PurchaseBill::query()->whereIn('gate_entry_id', $tokens->pluck('id'))->whereNotNull('number')->get()->keyBy('gate_entry_id');
        $salesByEntry = SalesBill::query()->whereIn('gate_entry_id', $tokens->pluck('id'))->whereNotNull('number')->get()->keyBy('gate_entry_id');
        $tokenRows = $tokens->map(function (GateEntry $e) use ($billsByEntry, $salesByEntry): array {
            $pb = $billsByEntry->get($e->id);
            $sb = $salesByEntry->get($e->id);
            $amount = $pb->net_paise ?? $sb?->total_paise;
            $open = $pb !== null ? $this->purchases->outstanding($pb) : ($sb !== null ? $this->sales->outstanding($sb) : null);

            return [
                'date' => CarbonImmutable::instance($e->arrived_at)->timezone('Asia/Karachi')->toDateString(), 'token' => $e->tokenLabel(),
                'vehicle' => $e->vehicleLabel(), 'product' => $e->itemLabel(), 'sauda' => $e->sauda?->number, 'sauda_id' => $e->sauda_id,
                'direction' => $e->direction, 'gross' => $e->gross_kg, 'tare' => $e->tare_kg, 'net' => $e->net_kg, 'payable' => $e->payable_kg,
                'rate' => $pb->rate_paise_per_maund ?? $sb?->rate_paise_per_maund, 'amount' => $amount,
                'bill' => $pb->number ?? $sb?->number, 'open' => $open, 'pay_status' => self::payStatus($amount, $open),
            ];
        })->filter(fn (array $r) => $payStatus === 'all' || $r['pay_status'] === $payStatus)->values();

        // Its bills (purchase bills and sales invoices) dated in the period.
        $bills = $this->bills($party, $from, $to)->filter(fn (array $b) => $payStatus === 'all' || $b['pay_status'] === $payStatus)->values();

        $contractor = $party->is_contractor ? $this->contractor($party, $from, $to, $vehicle, $payStatus) : null;

        $totalDr = array_sum(array_column($lines, 'dr'));
        $totalCr = array_sum(array_column($lines, 'cr'));

        return [
            'party' => [
                'name' => $party->name, 'code' => $party->code, 'contractor_code' => $party->contractor_code, 'phone' => $party->phone,
                'email' => $party->email, 'address' => trim(implode(', ', array_filter([$party->address, $party->city]))), 'roles' => implode(', ', $party->roleLabels()),
            ],
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'filters' => array_filter([
                'Type' => $type !== 'all' ? self::TYPES[$type] ?? $type : null, 'Vehicle' => $vehicle,
                'Payment status' => $payStatus !== 'all' ? self::PAYMENT_STATUSES[$payStatus] ?? $payStatus : null,
            ]),
            'opening' => $book['opening'], 'closing' => $book['closing'],
            'standing' => self::standing($book['closing']),
            'total_dr' => $totalDr, 'total_cr' => $totalCr,
            'lines' => $lines,
            'payments' => $payments,
            'tokens' => $tokenRows->all(),
            'by_vehicle' => self::group($tokenRows->all(), 'vehicle'),
            'by_sauda' => self::group($tokenRows->filter(fn (array $r) => $r['sauda'] !== null)->values()->all(), 'sauda'),
            'bills' => $bills->all(),
            'contractor' => $contractor,
            'summary' => [
                'trips' => $tokenRows->count(),
                'net_kg' => $tokenRows->reduce(fn (string $s, array $r) => bcadd($s, (string) ($r['net'] ?? '0'), 3), '0'),
                'billed' => (int) $bills->sum('amount'), 'open' => (int) $bills->sum('open'),
                'paid' => (int) array_sum(array_column(array_filter($payments, fn ($l) => $l['type'] === 'payment'), 'dr')),
                'received' => (int) array_sum(array_column(array_filter($payments, fn ($l) => $l['type'] === 'receipt'), 'cr')),
            ],
        ];
    }

    public static function standing(int $balance): string
    {
        return match (true) {
            $balance < 0 => 'The mill owes the party '.Money::paise(-$balance)->format(),
            $balance > 0 => 'The party owes the mill '.Money::paise($balance)->format(),
            default => 'The account is settled',
        };
    }

    public static function payStatus(?int $amount, ?int $open): ?string
    {
        if ($amount === null || $open === null) {
            return null;
        }

        return match (true) {
            $open <= 0 => 'paid', $open < $amount => 'partial', default => 'unpaid',
        };
    }

    /** @return Collection<int, array{kind: string, number: string|null, date: string, due: string, amount: int, open: int, pay_status: string|null}> */
    private function bills(Party $party, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $range = [$from->toDateString(), $to->toDateString()];

        return PurchaseBill::query()->where('party_id', $party->id)->whereNotNull('number')->where('status', PurchaseBill::POSTED)->whereBetween('bill_date', $range)->get()
            ->map(fn (PurchaseBill $b) => ['kind' => 'Purchase bill', 'number' => $b->number, 'date' => $b->bill_date->toDateString(), 'due' => $b->due_date->toDateString(),
                'amount' => $b->net_paise, 'open' => $open = $this->purchases->outstanding($b), 'pay_status' => self::payStatus($b->net_paise, $open)])
            ->concat(SalesBill::query()->where('party_id', $party->id)->whereNotNull('number')->where('status', SalesBill::POSTED)->whereBetween('bill_date', $range)->get()
                ->map(fn (SalesBill $b) => ['kind' => 'Sales invoice', 'number' => $b->number, 'date' => $b->bill_date->toDateString(), 'due' => $b->due_date->toDateString(),
                    'amount' => $b->total_paise, 'open' => $open = $this->sales->outstanding($b), 'pay_status' => self::payStatus($b->total_paise, $open)]))
            ->sortBy('date')->values();
    }

    /**
     * A labour contractor's side: the tokens its vehicles worked (with the charge each earns), its bills and payments.
     *
     * @return array<string, mixed>
     */
    private function contractor(Party $party, CarbonImmutable $from, CarbonImmutable $to, ?string $vehicle, string $payStatus): array
    {
        $entries = GateEntry::query()->with('contractorBill')->where('contractor_party_id', $party->id)->where('status', '!=', 'cancelled')
            ->where('arrived_at', '>=', $from->startOfDay()->utc())->where('arrived_at', '<=', $to->endOfDay()->utc())
            ->when($vehicle !== null, fn ($q) => $q->where('registration_no', 'ilike', "%{$vehicle}%"))
            ->orderBy('arrived_at')->get();
        $billLines = ContractorBillLine::query()->whereIn('contractor_bill_id', $entries->pluck('contractor_bill_id')->filter()->unique())->get()
            ->keyBy(fn ($l) => (string) ($l->source['gate_entry_id'] ?? ''));
        $tokens = $entries->map(function (GateEntry $e) use ($party, $billLines): array {
            $line = $billLines->get($e->id);
            $source = $line?->source;
            if ($source === null) {
                try {
                    $calc = $this->contractorVehicles->line($party, $e);
                    $source = $calc['source'];
                    $charge = $calc['amount_paise'];
                } catch (BusinessRuleViolation $ex) {
                    $charge = null;
                    $source = ['note' => $ex->getMessage()];
                }
            } else {
                $charge = $line->amount_paise;
            }

            return [
                'date' => CarbonImmutable::instance($e->arrived_at)->timezone('Asia/Karachi')->toDateString(), 'token' => $e->tokenLabel(),
                'vehicle' => $e->vehicleLabel(), 'work' => $e->contractor_work, 'gross' => $e->gross_kg, 'tare' => $e->tare_kg, 'net' => $e->net_kg,
                'billable' => $source['billable_kg'] ?? null, 'rate' => isset($source['rate_paise']) ? (int) $source['rate_paise'] : null,
                'basis' => $source['basis_label'] ?? null, 'rate_code' => $source['rate_code'] ?? null, 'charge' => $charge,
                'bill' => $e->contractorBill?->number, 'note' => $source['note'] ?? null,
            ];
        });
        $bills = ContractorBill::query()->where('party_id', $party->id)->where('status', 'approved')
            ->where('period_to', '>=', $from->toDateString())->where('period_from', '<=', $to->toDateString())->orderBy('period_from')->get()
            ->filter(fn (ContractorBill $b) => $payStatus === 'all' || $b->paymentStatus() === $payStatus)
            ->map(fn (ContractorBill $b) => ['number' => $b->number, 'period' => $b->period_from->format('d M').' – '.$b->period_to->format('d M Y'),
                'gross' => $b->gross_paise, 'deductions' => $b->deduction_paise + $b->wht_paise, 'payable' => $b->payable_paise, 'paid' => $b->paid_paise,
                'balance' => $b->balancePaise(), 'pay_status' => $b->paymentStatus()])->values();
        $payments = ContractorBillPayment::query()->with(['bill', 'account'])->whereHas('bill', fn ($q) => $q->where('party_id', $party->id))
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->orderBy('paid_on')->get()
            ->map(fn (ContractorBillPayment $p) => ['date' => $p->paid_on->toDateString(), 'bill' => $p->bill->number, 'amount' => $p->amount_paise,
                'from' => $p->account->name ?? $p->mode, 'reference' => $p->reference]);

        return [
            'tokens' => $tokens->all(),
            'unbilled_paise' => (int) $tokens->whereNull('bill')->sum('charge'),
            'bills' => $bills->all(),
            'payments' => $payments->all(),
            'payable' => (int) $bills->sum('payable'), 'paid' => (int) $bills->sum('paid'), 'balance' => (int) $bills->sum('balance'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{key: string, trips: int, net_kg: string, amount: int}>
     */
    private static function group(array $rows, string $by): array
    {
        return collect($rows)->groupBy($by)->map(fn (Collection $g, string $key) => [
            'key' => $key, 'trips' => $g->count(),
            'net_kg' => $g->reduce(fn (string $s, array $r) => bcadd($s, (string) ($r['net'] ?? '0'), 3), '0'),
            'amount' => (int) $g->sum('amount'),
        ])->sortBy('key')->values()->all();
    }
}
