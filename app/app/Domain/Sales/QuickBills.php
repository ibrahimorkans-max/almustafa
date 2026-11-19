<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\PaymentService;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pricing\PricingService;
use App\Domain\Procurement\SaudaService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Payment;
use App\Models\Accounts\Voucher;
use App\Models\Gate\GateEntry;
use App\Models\Inventory\StockEntry;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\QuickBill;
use App\Models\Sales\QuickBillCharge;
use App\Models\Sales\QuickBillLine;
use App\Models\Sales\QuickBillPayment;
use App\Support\Values\Money;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Quick bills, made on the spot (counter, gate, weighbridge):
 *  - sale (any size: a bike, by hand), purchase (paddy, after the gate entry and the lab), rice given for paddy
 *    (exchange), milling somebody's paddy for labour (mazdoori), or weighing an outside vehicle;
 *  - one or many lines; picking a product fills its details and today's rate (PricingService), which may be changed;
 *  - every line's weights are typed: gross − empty − (bags × deduction per bag) − other deduction = net; amount =
 *    net ÷ 40 × rate per maund;
 *  - charges (vehicle rent, labour, anything typed) are added to the bill or taken off it;
 *  - paid now (any mode, any amount), in part, or later (receive()/pay()); a walk-in customer without a party pays in full.
 * Posting makes one voucher (party or walk-in ledger against sales / purchase / income ledgers), moves the stock (goods
 * out leave the godown, goods in enter it), and records the money: received money is a receipt voucher; money paid out
 * goes through PaymentService (its approval rules).
 */
final class QuickBills
{
    public const RIGHTS = ['dispatch.manage', 'ledger.post'];

    public function __construct(
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly StockLedger $stock,
        private readonly DocumentNumbers $numbers,
        private readonly ActorContext $actor,
    ) {}

    public static function canCreate(): bool
    {
        $account = app(ActorContext::class)->account();

        return $account !== null && collect(self::RIGHTS)->contains(fn (string $p) => $account->hasPermission($p));
    }

    /**
     * What a product line starts with when the product is picked: its variety, bag size, unit and today's rate.
     *
     * @return array{description: string, variety: string|null, bag_weight_kg: string|null, rate: string|null, category: string}
     */
    public static function productDefaults(Product $product): array
    {
        $rate = app(PricingService::class)->listRate($product);

        return [
            'description' => $product->name,
            'variety' => $product->variety,
            'bag_weight_kg' => $product->bag_weight_kg,
            'rate' => $rate === null ? null : Money::paise($rate)->toDecimal(),
            'category' => $product->category,
        ];
    }

    /**
     * Gross − empty − bags × deduction per bag − other deduction, and its amount at the rate per maund.
     *
     * @return array{net_kg: string, amount_paise: int}
     */
    public static function lineAmount(string $grossKg, string $tareKg, ?int $bags, string $perBagKg, string $otherKg, int $ratePaisePerMaund): array
    {
        $deduction = bcadd(bcmul((string) ($bags ?? 0), $perBagKg, 3), $otherKg, 3);
        $net = bcsub(bcsub($grossKg, $tareKg, 3), $deduction, 3);
        // net kg ÷ 40 × rate, to the nearest paisa (half up)
        $exact = bcdiv(bcmul($net, (string) $ratePaisePerMaund, 6), '40', 6);
        $amount = (int) bcadd($exact, bccomp($exact, '0', 6) >= 0 ? '0.5' : '-0.5', 0);

        return ['net_kg' => $net, 'amount_paise' => $amount];
    }

    /**
     * Makes and posts the bill.
     *
     * @param  array<string, mixed>  $data  kind, billed_at, party_id|customer_name, vehicle…, lines[], charges[], payment{}
     */
    public function create(array $data): QuickBill
    {
        if (! self::canCreate()) {
            throw new BusinessRuleViolation('You need the right to manage dispatch or to post vouchers for this.');
        }
        $kind = (string) ($data['kind'] ?? '');
        if (! isset(QuickBill::KINDS[$kind])) {
            throw new BusinessRuleViolation('Choose what the bill is for.');
        }
        $billedAt = CarbonImmutable::parse((string) ($data['billed_at'] ?? 'now'), 'Asia/Karachi');
        if ($billedAt->isAfter(now()->addHour())) {
            throw new BusinessRuleViolation('The bill\'s date and time cannot be in the future.');
        }
        $party = filled($data['party_id'] ?? null) ? Party::query()->findOrFail($data['party_id']) : null;
        // A bill on a sauda: the deal's own party, and the weight of its product counts against what is left.
        $sauda = filled($data['sauda_id'] ?? null) ? Sauda::query()->with('product')->findOrFail($data['sauda_id']) : null;
        if ($sauda !== null) {
            $direction = match ($kind) {
                'sale', 'exchange' => 'sale', 'purchase' => 'purchase', default => null
            };
            if ($direction === null || $sauda->direction !== $direction) {
                throw new BusinessRuleViolation("Sauda {$sauda->number} is not a {$kind} deal.");
            }
            if (! $sauda->isOpenForDelivery()) {
                throw new BusinessRuleViolation("Sauda {$sauda->number} is {$sauda->status}; it cannot take deliveries.");
            }
            if ($party !== null && $party->id !== $sauda->party_id) {
                throw new BusinessRuleViolation("Sauda {$sauda->number} belongs to another party.");
            }
            $party ??= Party::query()->findOrFail($sauda->party_id);
        }
        if ($party === null && blank($data['customer_name'] ?? null)) {
            throw new BusinessRuleViolation('Choose the party, or type the customer\'s name.');
        }
        $vehicle = $data['vehicle_type'] ?? null;
        if ($vehicle !== null && $vehicle !== '' && ! isset(QuickBill::VEHICLES[$vehicle])) {
            throw new BusinessRuleViolation('Unknown vehicle type.');
        }

        $lines = $this->lines($kind, (array) ($data['lines'] ?? []), $data);
        $charges = $this->charges((array) ($data['charges'] ?? []));
        $out = array_sum(array_map(fn ($l) => $l['direction'] === 'out' ? $l['amount_paise'] : 0, $lines));
        $in = array_sum(array_map(fn ($l) => $l['direction'] === 'in' ? $l['amount_paise'] : 0, $lines));
        $service = array_sum(array_map(fn ($l) => $l['direction'] === 'service' ? $l['amount_paise'] : 0, $lines));
        $add = array_sum(array_map(fn ($c) => $c['effect'] === 'add' ? $c['amount_paise'] : 0, $charges));
        $less = array_sum(array_map(fn ($c) => $c['effect'] === 'less' ? $c['amount_paise'] : 0, $charges));
        $net = $out + $service + $add - $in - $less;
        $saudaKg = null;
        if ($sauda !== null) {
            $saudaKg = '0.000';
            foreach ($lines as $l) {
                if ($l['product_id'] === $sauda->product_id && $l['direction'] === ($sauda->direction === 'sale' ? 'out' : 'in')) {
                    $saudaKg = bcadd($saudaKg, $l['net_kg'], 3);
                }
            }
            if (bccomp($saudaKg, '0', 3) <= 0) {
                throw new BusinessRuleViolation("Add a line of the sauda's product ({$sauda->product->name}).");
            }
        }

        $pay = $this->payment($data['payment'] ?? null);
        if ($pay !== null && $pay['amount_paise'] > abs($net)) {
            throw new BusinessRuleViolation(sprintf('The payment is more than the bill (%s).', Money::paise(abs($net))->format()));
        }
        if ($party === null && $net > 0 && ($pay['amount_paise'] ?? 0) < $net) {
            throw new BusinessRuleViolation('A walk-in customer pays the whole bill now. For credit (unpaid), choose a party.');
        }
        if ($party === null && $net < 0) {
            throw new BusinessRuleViolation('The mill pays on this bill: choose the party who is paid.');
        }

        return DB::transaction(function () use ($kind, $billedAt, $party, $sauda, $saudaKg, $data, $lines, $charges, $out, $in, $service, $add, $less, $net, $pay): QuickBill {
            $bill = QuickBill::query()->create([
                'number' => $this->numbers->next(DocumentNumbers::QUICK_BILL, $billedAt),
                'kind' => $kind, 'billed_at' => $billedAt->utc(),
                'party_id' => $party?->id,
                'customer_name' => $party === null ? mb_substr(trim((string) $data['customer_name']), 0, 120) : null,
                'customer_phone' => filled($data['customer_phone'] ?? null) ? mb_substr(trim((string) $data['customer_phone']), 0, 20) : null,
                'sauda_id' => $sauda?->id, 'sauda_kg' => $saudaKg,
                'gate_entry_id' => filled($data['gate_entry_id'] ?? null) ? GateEntry::query()->findOrFail($data['gate_entry_id'])->id : null,
                'vehicle_type' => filled($data['vehicle_type'] ?? null) ? $data['vehicle_type'] : null,
                'vehicle_no' => filled($data['vehicle_no'] ?? null) ? mb_strtoupper(mb_substr(trim((string) $data['vehicle_no']), 0, 20)) : null,
                'driver_name' => filled($data['driver_name'] ?? null) ? mb_substr(trim((string) $data['driver_name']), 0, 80) : null,
                'first_weight_kg' => $kind === 'weighing' ? $this->kg($data['first_weight_kg'] ?? null, 'First weight') : null,
                'second_weight_kg' => $kind === 'weighing' ? $this->kg($data['second_weight_kg'] ?? null, 'Second weight') : null,
                'out_paise' => $out, 'in_paise' => $in, 'service_paise' => $service,
                'charges_add_paise' => $add, 'charges_less_paise' => $less, 'net_paise' => $net,
                'note' => filled($data['note'] ?? null) ? mb_substr((string) $data['note'], 0, 2000) : null,
                'created_by' => $this->actor->accountId(),
            ]);
            foreach ($lines as $i => $l) {
                QuickBillLine::query()->create(['quick_bill_id' => $bill->id, 'line_no' => $i + 1] + array_diff_key($l, ['product' => true, 'godown' => true]));
            }
            foreach ($charges as $c) {
                QuickBillCharge::query()->create(['quick_bill_id' => $bill->id] + $c);
            }

            $this->postVoucher($bill, $party, $lines, $charges, $net);
            $this->moveStock($bill, $lines);
            if ($sauda !== null && $saudaKg !== null) {
                app(SaudaService::class)->recordBillDelivery($sauda, $bill, Weight::kg($saudaKg));
            }
            if ($pay !== null && $pay['amount_paise'] > 0) {
                $this->settle($bill, $party, $pay);
            }

            return $bill->refresh();
        });
    }

    /**
     * Cancels a bill made by mistake (`voucher.reverse`, with the reason): its voucher and every receipt are reversed,
     * the stock it moved is moved back, the sauda gets its weight back. Refused while money paid out on it is waiting
     * for approval (reject that request first) or already paid (take it back first), and when the stock it brought in
     * is already used.
     */
    public function cancel(QuickBill $bill, string $reason): QuickBill
    {
        $account = $this->actor->account();
        if (! ($account?->hasPermission('voucher.reverse') ?? false)) {
            throw new BusinessRuleViolation("You need 'voucher.reverse' to cancel a bill.");
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new BusinessRuleViolation('Give the reason for cancelling (a few words).');
        }

        return DB::transaction(function () use ($bill, $reason): QuickBill {
            $bill = QuickBill::query()->lockForUpdate()->findOrFail($bill->id);
            if ($bill->status !== QuickBill::POSTED) {
                throw new BusinessRuleViolation('This bill is already cancelled.');
            }
            $why = "Cancelled bill {$bill->number}: {$reason}";
            foreach (QuickBillPayment::query()->where('quick_bill_id', $bill->id)->get() as $p) {
                if ($p->payment_id !== null) {
                    $status = Payment::query()->whereKey($p->payment_id)->value('status');
                    if ($status !== Payment::REJECTED) {
                        throw new BusinessRuleViolation($status === Payment::APPROVED
                            ? 'Money was already paid out on this bill: take it back (a receipt) before cancelling.'
                            : 'A payment on this bill is waiting for approval: reject it first (Approvals), then cancel.');
                    }
                } elseif ($p->voucher_id !== null) {
                    $this->books->reverse(Voucher::query()->findOrFail($p->voucher_id), $why);
                }
            }
            if ($bill->voucher_id !== null) {
                $this->books->reverse(Voucher::query()->findOrFail($bill->voucher_id), $why);
            }
            $entries = StockEntry::query()->with(['product', 'godown'])->where('source_type', 'quick_bill')->where('source_id', $bill->id)
                ->whereNull('reverses_id')->get();
            foreach ($entries as $entry) {
                $this->stock->reverse($entry, $why);
            }
            if ($bill->sauda_id !== null && $bill->sauda_kg !== null && bccomp((string) $bill->sauda_kg, '0', 3) > 0) {
                app(SaudaService::class)->returnBillDelivery(Sauda::query()->findOrFail($bill->sauda_id), $bill, Weight::kg((string) $bill->sauda_kg));
            }
            $bill->forceFill(['status' => QuickBill::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $this->actor->accountId(),
                'cancel_reason' => mb_substr($reason, 0, 500)])->save();
            DispatchOrder::query()->where('quick_bill_id', $bill->id)->where('status', DispatchOrder::DISPATCHED)
                ->get()->each(fn ($o) => $o->forceFill(['status' => DispatchOrder::CANCELLED,
                    'note' => trim(($o->note ? $o->note."\n" : '').$why)])->save());

            return $bill;
        });
    }

    /**
     * More money on a bill later (any mode, any amount, as many times as needed): received, or paid out.
     *
     * @param  array{amount: string, mode: string, ledger_id: string, reference?: string|null, paid_at?: string|null}  $payment
     */
    public function settlePayment(QuickBill $bill, array $payment): QuickBillPayment
    {
        if (! self::canCreate()) {
            throw new BusinessRuleViolation('You need the right to manage dispatch or to post vouchers for this.');
        }

        return DB::transaction(function () use ($bill, $payment): QuickBillPayment {
            $bill = QuickBill::query()->lockForUpdate()->findOrFail($bill->id);
            if ($bill->status !== QuickBill::POSTED) {
                throw new BusinessRuleViolation('This bill is cancelled.');
            }
            $pay = $this->payment($payment) ?? throw new BusinessRuleViolation('Type the amount.');
            if ($pay['amount_paise'] > $bill->balancePaise()) {
                throw new BusinessRuleViolation(sprintf('Only %s is left to pay on this bill.', Money::paise($bill->balancePaise())->format()));
            }
            $party = $bill->party_id ? Party::query()->find($bill->party_id) : null;

            return $this->settle($bill, $party, $pay);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function lines(string $kind, array $rows, array $data): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            $direction = (string) ($row['direction'] ?? match ($kind) {
                'purchase' => 'in', 'milling', 'weighing' => 'service', default => 'out'
            });
            $allowed = match ($kind) {
                'sale' => ['out'], 'purchase' => ['in'], 'exchange' => ['out', 'in'], default => ['service'],
            };
            if (! in_array($direction, $allowed, true)) {
                throw new BusinessRuleViolation("Line {$n}: not allowed on this bill.");
            }
            $product = filled($row['product_id'] ?? null) ? Product::query()->findOrFail($row['product_id']) : null;
            $description = trim((string) ($row['description'] ?? '')) ?: ($product->name ?? '');
            if ($description === '') {
                throw new BusinessRuleViolation("Line {$n}: choose the product or type what it is.");
            }
            $godown = filled($row['godown_id'] ?? null) ? Godown::query()->findOrFail($row['godown_id']) : null;
            $stock = $direction !== 'service' && $product !== null && $product->is_stock_item;
            if ($stock && $godown === null) {
                throw new BusinessRuleViolation("Line {$n}: choose the godown {$product->name} ".($direction === 'out' ? 'leaves.' : 'goes into.'));
            }
            $bags = filled($row['bags'] ?? null) ? (int) $row['bags'] : null;
            if ($bags !== null && ($bags < 0 || $bags > 1_000_000)) {
                throw new BusinessRuleViolation("Line {$n}: the number of bags is not right.");
            }
            $gross = $this->kg($row['gross_kg'] ?? null, "Line {$n}: weight");
            $tare = $this->kg($row['tare_kg'] ?? '0', "Line {$n}: empty weight", true);
            $perBag = $this->kg($row['deduction_per_bag_kg'] ?? '0', "Line {$n}: deduction per bag", true);
            $other = $this->kg($row['other_deduction_kg'] ?? '0', "Line {$n}: other deduction", true);
            $rate = $this->rupees($row['rate'] ?? null, "Line {$n}: rate per maund", true);
            $calc = self::lineAmount($gross, $tare, $bags, $perBag, $other, $rate);
            if (bccomp($calc['net_kg'], '0', 3) <= 0 && $direction !== 'service') {
                throw new BusinessRuleViolation("Line {$n}: the deductions take the whole weight.");
            }
            if (bccomp($calc['net_kg'], '0', 3) < 0) {
                throw new BusinessRuleViolation("Line {$n}: the deductions are more than the weight.");
            }
            $out[] = [
                'direction' => $direction, 'product_id' => $product?->id, 'description' => mb_substr($description, 0, 150),
                'variety' => filled($row['variety'] ?? null) ? mb_substr(trim((string) $row['variety']), 0, 80) : $product?->variety,
                'godown_id' => $stock ? $godown?->id : null, 'bags' => $bags,
                'gross_kg' => $gross, 'tare_kg' => $tare, 'deduction_per_bag_kg' => $perBag, 'other_deduction_kg' => $other,
                'net_kg' => $calc['net_kg'], 'rate_paise_per_maund' => $rate, 'amount_paise' => $calc['amount_paise'],
                'product' => $product, 'godown' => $stock ? $godown : null,
            ];
        }
        if ($out === []) {
            throw new BusinessRuleViolation('Add at least one line.');
        }
        if ($kind === 'exchange' && (! in_array('out', array_column($out, 'direction'), true) || ! in_array('in', array_column($out, 'direction'), true))) {
            throw new BusinessRuleViolation('Rice for paddy: add the paddy taken (in) and the rice given (out).');
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{label: string, effect: string, amount_paise: int}>
     */
    private function charges(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $row) {
            if (blank($row['label'] ?? null) && blank($row['amount'] ?? null)) {
                continue;
            }
            $effect = (string) ($row['effect'] ?? 'add');
            if (! in_array($effect, ['add', 'less'], true)) {
                throw new BusinessRuleViolation('A charge is added to the bill or taken off it.');
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                throw new BusinessRuleViolation('Name each charge (e.g. vehicle rent, labour).');
            }
            $amount = $this->rupees($row['amount'] ?? null, "Charge {$label}");
            if ($amount > 0) {
                $out[] = ['label' => mb_substr($label, 0, 100), 'effect' => $effect, 'amount_paise' => $amount];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $p
     * @return array{amount_paise: int, mode: string, ledger: LedgerAccount, reference: string|null, paid_at: CarbonImmutable}|null
     */
    private function payment(?array $p): ?array
    {
        if ($p === null || blank($p['amount'] ?? null) || $this->rupees($p['amount'], 'Amount paid', true) === 0) {
            return null;
        }
        $mode = (string) ($p['mode'] ?? 'cash');
        if (! isset(QuickBill::PAYMENT_MODES[$mode])) {
            throw new BusinessRuleViolation('Choose how it is paid.');
        }
        $ledger = filled($p['ledger_id'] ?? null) ? LedgerAccount::query()->find($p['ledger_id']) : null;
        if ($ledger === null || ! in_array($ledger->kind, ['cash', 'bank'], true) || $ledger->status !== 'active') {
            throw new BusinessRuleViolation('Choose the cash or bank account the money goes into (or out of).');
        }
        if ($mode === 'cash' && $ledger->kind !== 'cash') {
            throw new BusinessRuleViolation('Cash goes into a cash account.');
        }
        if (in_array($mode, ['cheque', 'pay_order'], true) && blank($p['reference'] ?? null)) {
            throw new BusinessRuleViolation('Type the cheque / pay order number.');
        }

        return [
            'amount_paise' => $this->rupees($p['amount'], 'Amount paid'), 'mode' => $mode, 'ledger' => $ledger,
            'reference' => filled($p['reference'] ?? null) ? mb_substr(trim((string) $p['reference']), 0, 60) : null,
            'paid_at' => CarbonImmutable::parse((string) ($p['paid_at'] ?? 'now'), 'Asia/Karachi'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $charges
     */
    private function postVoucher(QuickBill $bill, ?Party $party, array $lines, array $charges, int $net): void
    {
        $counter = $party !== null ? $this->chart->partyLedger($party) : $this->chart->system('walk_in');
        $memo = $bill->number;
        $v = [];
        foreach ($lines as $l) {
            $product = $l['product'];
            $what = $l['description'].' '.Weight::kg($l['net_kg'])->in('maund', 2).' md @ '.Money::paise($l['rate_paise_per_maund'])->format();
            $v[] = match ($l['direction']) {
                'out' => ['account' => $this->chart->system($product?->category === 'rice' ? 'sales_rice' : 'sales_byproduct'), 'cr' => $l['amount_paise'], 'memo' => $what],
                'in' => ['account' => $this->chart->system($product === null || $product->category === 'paddy' ? 'purchase_paddy' : 'purchase_other'), 'dr' => $l['amount_paise'], 'memo' => $what],
                default => ['account' => $this->chart->system($bill->kind === 'weighing' ? 'weighing_income' : 'milling_income'), 'cr' => $l['amount_paise'], 'memo' => $what],
            };
        }
        foreach ($charges as $c) {
            $v[] = $c['effect'] === 'add'
                ? ['account' => $this->chart->system('charges_recovered'), 'cr' => $c['amount_paise'], 'memo' => $c['label']]
                : ['account' => $this->chart->system('sales_expenses'), 'dr' => $c['amount_paise'], 'memo' => $c['label']];
        }
        $v[] = $net >= 0 ? ['account' => $counter, 'dr' => $net, 'memo' => $memo] : ['account' => $counter, 'cr' => -$net, 'memo' => $memo];

        $type = match ($bill->kind) {
            'sale' => 'sales', 'purchase' => 'purchase', default => 'journal'
        };
        $nonZero = array_filter($v, fn ($l) => ($l['dr'] ?? 0) > 0 || ($l['cr'] ?? 0) > 0);
        if (count($nonZero) < 2) {
            return;   // nothing of value (a free weighing, say): no voucher
        }
        $voucher = $this->books->post($type, $bill->billed_at->setTimezone('Asia/Karachi'), $v,
            QuickBill::KINDS[$bill->kind]." {$bill->number} — {$bill->customer()}", ['source_type' => 'quick_bill', 'source_id' => $bill->id]);
        $bill->forceFill(['voucher_id' => $voucher->id])->save();
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function moveStock(QuickBill $bill, array $lines): void
    {
        foreach ($lines as $l) {
            if ($l['godown'] === null || $l['product'] === null) {
                continue;
            }
            $kg = Weight::kg($l['net_kg']);
            $this->stock->post($l['product'], $l['godown'], $l['direction'] === 'out' ? Weight::zero()->minus($kg) : $kg,
                $l['direction'] === 'out' ? StockEntry::DISPATCH : StockEntry::RECEIPT,
                ['source_type' => 'quick_bill', 'source_id' => $bill->id, 'note' => $bill->number], $bill->billed_at);
        }
    }

    /** @param  array{amount_paise: int, mode: string, ledger: LedgerAccount, reference: string|null, paid_at: CarbonImmutable}  $pay */
    private function settle(QuickBill $bill, ?Party $party, array $pay): QuickBillPayment
    {
        $counter = $party !== null ? $this->chart->partyLedger($party) : $this->chart->system('walk_in');
        $mode = QuickBill::PAYMENT_MODES[$pay['mode']].($pay['reference'] ? " {$pay['reference']}" : '');
        $voucherId = null;
        $paymentId = null;
        if ($bill->net_paise > 0) {
            // Received: posted at once.
            $voucherId = $this->books->post('receipt', $pay['paid_at'], [
                ['account' => $pay['ledger'], 'dr' => $pay['amount_paise'], 'memo' => $mode],
                ['account' => $counter, 'cr' => $pay['amount_paise'], 'memo' => $bill->number],
            ], "Received on {$bill->number} from {$bill->customer()} ({$mode})", ['source_type' => 'quick_bill', 'source_id' => $bill->id])->id;
        } else {
            // Paid out: a payment request, released by its approval rules (an Owner's own request is applied at once).
            if ($party === null) {
                throw new BusinessRuleViolation('The mill pays on this bill: choose the party who is paid.');
            }
            $paymentMode = match ($pay['mode']) {
                'cash' => 'cash', 'cheque' => 'cheque', 'pay_order' => 'pay_order', 'rtgs' => 'rtgs', 'raast' => 'raast', default => 'ibft',
            };
            $paymentId = app(PaymentService::class)->request($party, $paymentMode, $pay['ledger'], $pay['amount_paise'], $pay['paid_at'], [],
                "{$bill->number}".($pay['reference'] ? " · {$pay['reference']}" : ''))->id;
        }
        $row = QuickBillPayment::query()->create([
            'quick_bill_id' => $bill->id, 'direction' => $bill->net_paise > 0 ? 'in' : 'out', 'mode' => $pay['mode'],
            'ledger_id' => $pay['ledger']->id, 'amount_paise' => $pay['amount_paise'], 'reference' => $pay['reference'],
            'paid_at' => $pay['paid_at']->utc(), 'voucher_id' => $voucherId, 'payment_id' => $paymentId,
            'created_by' => $this->actor->accountId(), 'created_at' => now(),
        ]);
        $bill->forceFill(['paid_paise' => $bill->paid_paise + $pay['amount_paise']])->save();

        return $row;
    }

    private function kg(mixed $value, string $what, bool $zeroOk = false): string
    {
        $v = trim((string) $value);
        if ($v === '' && $zeroOk) {
            return '0.000';
        }
        if (! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $v) || (! $zeroOk && bccomp($v, '0', 3) <= 0)) {
            throw new BusinessRuleViolation("{$what}: type the weight in kg (up to 3 decimals).");
        }

        return bcadd($v, '0', 3);
    }

    private function rupees(mixed $value, string $what, bool $zeroOk = false): int
    {
        if ($zeroOk && blank($value)) {
            return 0;
        }
        try {
            $paise = Money::rupees((string) ($value ?? ''))->paise;
        } catch (InvalidArgumentException) {
            throw new BusinessRuleViolation("{$what}: type the amount in rupees.");
        }
        if ($paise < 0 || (! $zeroOk && $paise === 0)) {
            throw new BusinessRuleViolation("{$what}: type the amount in rupees.");
        }

        return $paise;
    }
}
