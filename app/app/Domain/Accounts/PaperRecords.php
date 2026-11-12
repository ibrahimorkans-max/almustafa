<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\PaperRecord;
use App\Models\Inventory\StockEntry;
use App\Models\Masters\Driver;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\Vehicle;
use App\Support\Security\InputGuard;
use App\Support\Values\Money;
use App\Support\Values\PakistanIds;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Old records from the mill's paper registers, brought into the system so its books, party accounts and stock
 * carry everything from before it started using the software:
 *
 *   opening_party   what a party owed the mill, or the mill owed it, on the start date   (Dr / Cr party ↔ Opening balances)
 *   opening_ledger  cash in hand, a bank balance, a loan, capital, an asset …              (any ledger ↔ Opening balances)
 *   opening_stock   paddy, rice, by-products in a godown (and their value)                 (stock + Opening stock ↔ Opening balances)
 *   purchase        bought from a party                                                    (Dr purchases, Cr party; stock in)
 *   sale            sold to a party                                                        (Dr party, Cr sales; stock out)
 *   payment         paid to a party from cash or bank                                      (Dr party, Cr cash/bank)
 *   receipt         received from a party into cash or bank                                (Dr cash/bank, Cr party)
 *   expense         an expense paid from cash or bank                                      (Dr expense, Cr cash/bank)
 *   journal         any other entry between two ledgers                                    (Dr one, Cr the other)
 *   weighment       an old weighbridge slip, kept as written (vehicle, weights)            (nothing posted)
 *   note            anything else from a register, kept as written                         (nothing posted)
 *
 * Every posting is dated on its paper date and marked as from paper; parties are not sent messages about them. Each
 * record keeps where it was written (register, page, paper number) and may carry a photo of the page. Records are
 * never edited: a wrong one is cancelled (its voucher and stock movement reversed on the same date) and entered again.
 * A spreadsheet (CSV, saved from Excel) brings many in at once: each row on its own, a bad row reported, not posted.
 */
final class PaperRecords
{
    /** Voucher and stock source of every posting made from paper (so it is told apart, and no party message goes out). */
    public const SOURCE = 'paper_record';

    public const KINDS = [
        'opening_party' => 'Opening balance — party',
        'opening_ledger' => 'Opening balance — cash, bank or other account',
        'opening_stock' => 'Opening stock',
        'purchase' => 'Purchase (bought from a party)',
        'sale' => 'Sale (sold to a party)',
        'payment' => 'Payment made to a party',
        'receipt' => 'Money received from a party',
        'expense' => 'Expense paid',
        'journal' => 'Journal / adjustment',
        'weighment' => 'Weighbridge slip (kept as written)',
        'note' => 'Other register entry (kept as written)',
    ];

    /** Kinds that move stock only (the rest post to the books, or are kept as written). */
    public const STOCK_KINDS = ['opening_stock'];

    public const PARTY_KINDS = ['opening_party', 'purchase', 'sale', 'payment', 'receipt'];

    public const KEPT_KINDS = ['weighment', 'note'];

    /** Kinds that carry the vehicle the goods came or went in (its number, driver, bags, bilty). */
    public const VEHICLE_KINDS = ['purchase', 'sale', 'weighment', 'note'];

    /** Kinds that carry how the money moved (mode, cheque / transaction no., bank, who paid or received it). */
    public const PAYMENT_KINDS = ['payment', 'receipt', 'expense'];

    /** How money moved, as written on the paper. */
    public const PAYMENT_MODES = ['cash' => 'Cash', 'cheque' => 'Cheque', 'pay_order' => 'Pay order', 'ibft' => 'IBFT (online transfer)',
        'rtgs' => 'RTGS (PRISM)', 'raast' => 'Raast', 'deposit' => 'Cash deposited in bank', 'mobile' => 'JazzCash / Easypaisa'];

    /** Modes that go through a bank account (not cash in hand). */
    public const BANK_MODES = ['ibft', 'rtgs', 'raast', 'deposit'];

    /** Spreadsheet columns, in the template's order. */
    public const COLUMNS = ['kind', 'date', 'register_page', 'paper_number', 'party', 'party_phone', 'party_cnic', 'party_address', 'party_city',
        'amount', 'side', 'account', 'cash_bank', 'credit_account', 'payment_mode', 'reference', 'cheque_date', 'party_bank', 'handled_by',
        'product', 'godown', 'quantity_kg', 'rate_per_maund', 'vehicle', 'vehicle_type', 'driver_name', 'driver_phone', 'bags', 'bilty_no',
        'gross_kg', 'tare_kg', 'description'];

    public const MAX_ROWS = 5000;

    public function __construct(
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly StockLedger $stock,
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Enters one old record and posts it.
     *
     * @param  array<string, mixed>  $data  kind, date, book_ref, paper_number, party_id, description, amount (rupees), side
     *                                      (receivable|payable for a party, dr|cr for a ledger), account_id, cash_bank_id,
     *                                      credit_account_id, product_id, godown_id, quantity_kg, rate_per_maund, vehicle,
     *                                      gross_kg, tare_kg
     */
    public function record(array $data, ?string $batch = null): PaperRecord
    {
        $kind = (string) ($data['kind'] ?? '');
        if (! isset(self::KINDS[$kind])) {
            throw new BusinessRuleViolation('Choose what kind of record this is.');
        }
        $this->require($kind);
        $date = $this->date($data['date'] ?? null);
        $party = $this->party($data['party_id'] ?? null, in_array($kind, self::PARTY_KINDS, true));
        $text = fn (string $key, int $max) => ($v = trim(InputGuard::clean((string) ($data[$key] ?? '')))) === '' ? null : mb_substr($v, 0, $max);
        $paperNumber = $text('paper_number', 60);
        if ($paperNumber !== null && PaperRecord::query()->where('kind', $kind)->where('paper_number', $paperNumber)
            ->whereDate('record_date', $date->toDateString())->whereNull('cancelled_at')->exists()) {
            throw new BusinessRuleViolation(sprintf('%s no. %s of %s is already entered.', self::KINDS[$kind], $paperNumber, $date->format('d M Y')));
        }

        $id = (string) Str::uuid();
        $quantity = $this->weight($data['quantity_kg'] ?? null);
        $details = array_filter([
            'side' => $data['side'] ?? null, 'rate_per_maund' => $data['rate_per_maund'] ?? null,
            ...$this->vehicleDetails($data, $text), ...$this->paymentDetails($kind, $data, $text),
        ], fn ($v) => $v !== null && $v !== '');
        $amount = $this->amount($data['amount'] ?? null);
        if ($amount === null && $quantity !== null && filled($data['rate_per_maund'] ?? null)) {
            // Paper bills give the rate per maund (40 kg): the amount is worked out as on the bill.
            $rate = $this->amount($data['rate_per_maund']) ?? 0;
            $amount = (int) round(((float) $quantity->kg / 40) * $rate);
        }

        $paidVia = isset($details['payment_mode']) ? ', '.self::PAYMENT_MODES[$details['payment_mode']].(isset($details['reference']) ? ' '.$details['reference'] : '') : '';
        $narration = fn (string $what) => trim('From paper: '.$what.($paperNumber ? " no. {$paperNumber}" : '').$paidVia
            .(isset($details['vehicle']) ? ", vehicle {$details['vehicle']}" : '')
            .(($ref = $text('book_ref', 160)) ? " ({$ref})" : '').(($d = $text('description', 300)) ? " — {$d}" : ''));
        $source = ['source_type' => self::SOURCE, 'source_id' => $id];

        return DB::transaction(function () use ($kind, $date, $party, $data, $text, $paperNumber, $id, $quantity, $details, $amount, $narration, $source, $batch): PaperRecord {
            $voucher = null;
            $stockEntry = null;
            if ($party !== null) {
                $details['party'] = $this->partyDetails($party, $data);
            }
            if (isset($details['vehicle'])) {
                $details += $this->linkVehicle($details, $party);
            }
            $opening = fn () => $this->chart->system('opening_balance');
            $need = function () use ($amount): int {
                if ($amount === null || $amount <= 0) {
                    throw new BusinessRuleViolation('Enter the amount (more than zero).');
                }

                return $amount;
            };

            switch ($kind) {
                case 'opening_party':
                    $side = $data['side'] ?? '';
                    if (! in_array($side, ['receivable', 'payable'], true)) {
                        throw new BusinessRuleViolation('Say whether the party owed the mill (receivable) or the mill owed the party (payable).');
                    }
                    $ledger = $this->chart->partyLedger($party);
                    $voucher = $this->books->post('opening', $date, $side === 'receivable'
                        ? [['account' => $ledger, 'dr' => $need()], ['account' => $opening(), 'cr' => $need()]]
                        : [['account' => $opening(), 'dr' => $need()], ['account' => $ledger, 'cr' => $need()]],
                        $narration("opening balance of {$party->name}"), $source);
                    break;

                case 'opening_ledger':
                    $ledger = $this->ledger($data['account_id'] ?? null, 'Choose the account (cash, bank, loan, capital …).');
                    if ($ledger->party_id !== null) {
                        throw new BusinessRuleViolation('For a party, use "Opening balance — party".');
                    }
                    if ($ledger->system_key === 'opening_balance') {
                        throw new BusinessRuleViolation('Choose the account whose balance this is.');
                    }
                    $side = $data['side'] ?? '';
                    if (! in_array($side, ['dr', 'cr'], true)) {
                        throw new BusinessRuleViolation('Say whether the balance is debit (cash, bank, assets, money owed to the mill) or credit (loans, capital, money the mill owes).');
                    }
                    $voucher = $this->books->post('opening', $date, $side === 'dr'
                        ? [['account' => $ledger, 'dr' => $need()], ['account' => $opening(), 'cr' => $need()]]
                        : [['account' => $opening(), 'dr' => $need()], ['account' => $ledger, 'cr' => $need()]],
                        $narration("opening balance of {$ledger->name}"), $source);
                    break;

                case 'opening_stock':
                    [$product, $godown] = $this->place($data);
                    if ($quantity === null || $quantity->compare(Weight::zero()) <= 0) {
                        throw new BusinessRuleViolation('Enter the quantity in kg (more than zero).');
                    }
                    $stockEntry = $this->stock->post($product, $godown, $quantity, StockEntry::OPENING,
                        $source + ['note' => $narration("opening stock of {$product->name}")], $date->setTime(0, 0));
                    if ($amount !== null && $amount > 0) {
                        $voucher = $this->books->post('opening', $date, [['account' => $this->chart->system('opening_stock'), 'dr' => $amount], ['account' => $opening(), 'cr' => $amount]],
                            $narration("opening stock of {$product->name}, {$quantity->kg} kg in {$godown->name}"), $source);
                    }
                    break;

                case 'purchase':
                case 'sale':
                    $purchase = $kind === 'purchase';
                    $product = filled($data['product_id'] ?? null) ? $this->product($data['product_id']) : null;
                    $default = $purchase ? 'purchase_paddy' : ($product?->category === 'by_product' ? 'sales_byproduct' : 'sales_rice');
                    $ledger = filled($data['account_id'] ?? null) ? $this->ledger($data['account_id'], '') : $this->chart->system($default);
                    $partyLedger = $this->chart->partyLedger($party);
                    $voucher = $this->books->post($purchase ? 'purchase' : 'sales', $date, $purchase
                        ? [['account' => $ledger, 'dr' => $need()], ['account' => $partyLedger, 'cr' => $need()]]
                        : [['account' => $partyLedger, 'dr' => $need()], ['account' => $ledger, 'cr' => $need()]],
                        $narration(($purchase ? 'purchase from ' : 'sale to ').$party->name), $source);
                    if ($product !== null && filled($data['godown_id'] ?? null) && $quantity !== null && $quantity->compare(Weight::zero()) > 0) {
                        [$product, $godown] = $this->place($data);
                        $stockEntry = $this->stock->post($product, $godown, $purchase ? $quantity : Weight::zero()->minus($quantity),
                            $purchase ? StockEntry::RECEIPT : StockEntry::DISPATCH, $source + ['note' => $narration(($purchase ? 'bought from ' : 'sold to ').$party->name)], $date->setTime(12, 0));
                    }
                    break;

                case 'payment':
                case 'receipt':
                    $money = $this->moneyAccount($data['cash_bank_id'] ?? null, $details['payment_mode'] ?? null);
                    $partyLedger = $this->chart->partyLedger($party);
                    $voucher = $this->books->post($kind, $date, $kind === 'payment'
                        ? [['account' => $partyLedger, 'dr' => $need()], ['account' => $money, 'cr' => $need()]]
                        : [['account' => $money, 'dr' => $need()], ['account' => $partyLedger, 'cr' => $need()]],
                        $narration(($kind === 'payment' ? 'paid to ' : 'received from ').$party->name), $source);
                    break;

                case 'expense':
                    $ledger = $this->ledger($data['account_id'] ?? null, 'Choose the expense account.');
                    if (in_array($ledger->kind, ['bank', 'cash', 'party'], true)) {
                        throw new BusinessRuleViolation('Choose an expense account (for a party use a payment).');
                    }
                    $money = $this->moneyAccount($data['cash_bank_id'] ?? null, $details['payment_mode'] ?? null);
                    $voucher = $this->books->post('payment', $date, [['account' => $ledger, 'dr' => $need()], ['account' => $money, 'cr' => $need()]],
                        $narration("expense: {$ledger->name}"), $source);
                    break;

                case 'journal':
                    $dr = $this->ledger($data['account_id'] ?? null, 'Choose the account debited.');
                    $cr = $this->ledger($data['credit_account_id'] ?? null, 'Choose the account credited.');
                    if ($dr->id === $cr->id) {
                        throw new BusinessRuleViolation('Choose two different accounts.');
                    }
                    if (trim((string) ($data['description'] ?? '')) === '') {
                        throw new BusinessRuleViolation('A journal entry needs a description of what it was for.');
                    }
                    $voucher = $this->books->post('journal', $date, [['account' => $dr, 'dr' => $need()], ['account' => $cr, 'cr' => $need()]],
                        $narration('journal'), $source);
                    break;

                case 'weighment':
                    $gross = $this->weight($data['gross_kg'] ?? null);
                    $tare = $this->weight($data['tare_kg'] ?? null);
                    if ($text('vehicle', 30) === null || $gross === null || $tare === null) {
                        throw new BusinessRuleViolation('Enter the vehicle number and both weights (gross and tare, in kg).');
                    }
                    $net = $gross->minus($tare);
                    if ($net->isNegative()) {
                        $net = $tare->minus($gross);   // weighed empty first, as on an outgoing slip
                    }
                    $quantity = $net;
                    $details += ['gross_kg' => $gross->kg, 'tare_kg' => $tare->kg, 'net_kg' => $net->kg,
                        'product' => filled($data['product_id'] ?? null) ? $this->product($data['product_id'])->name : $text('product_name', 120)];
                    break;

                case 'note':
                    if ($text('description', 5000) === null) {
                        throw new BusinessRuleViolation('Write what the register says.');
                    }
                    break;
            }

            if ($voucher !== null) {
                $details['ledgers'] = $voucher->lines()->with('account')->get()->map(fn ($l) => [$l->account->name, $l->dr_paise, $l->cr_paise])->all();
            }
            $record = PaperRecord::query()->create([
                'kind' => $kind, 'record_date' => $date->toDateString(), 'book_ref' => $text('book_ref', 160), 'paper_number' => $paperNumber,
                'party_id' => $party?->id, 'description' => $text('description', 5000), 'amount_paise' => $amount,
                'quantity_kg' => $quantity?->kg, 'details' => array_filter($details, fn ($v) => $v !== null) ?: null,
                'voucher_id' => $voucher?->id, 'stock_entry_id' => $stockEntry?->id, 'import_batch' => $batch,
                'entered_by' => $this->actor->accountId(), 'created_at' => now(),
            ] + ['id' => $id]);
            $this->audit->record('paper_record.entered', $record, null, ['kind' => $kind, 'date' => $date->toDateString(),
                'amount_paise' => $amount, 'voucher' => $voucher?->number, 'batch' => $batch]);

            return $record;
        });
    }

    /** Cancels a wrong record: its voucher and stock movement are reversed (dated as the record), the record kept, marked. */
    public function cancel(PaperRecord $record, string $reason): PaperRecord
    {
        $this->require($record->kind);
        if ($record->isCancelled()) {
            throw new BusinessRuleViolation('This record is already cancelled.');
        }
        $reason = trim(InputGuard::clean($reason));
        if (mb_strlen($reason) < 5) {
            throw new BusinessRuleViolation('Say why it is cancelled.');
        }

        return DB::transaction(function () use ($record, $reason): PaperRecord {
            if ($record->voucher !== null) {
                $this->books->reverse($record->voucher, $reason, $record->record_date);
            }
            if ($record->stockEntry !== null) {
                $this->stock->reverse($record->stockEntry, 'Paper record cancelled: '.$reason);
            }
            $record->forceFill(['cancelled_at' => now(), 'cancelled_by' => $this->actor->accountId(), 'cancel_reason' => mb_substr($reason, 0, 300)])->save();
            $this->audit->record('paper_record.cancelled', $record, null, ['reason' => $reason]);

            return $record;
        });
    }

    /**
     * Brings in a spreadsheet (CSV with the template's columns). Each row is entered on its own; a row with a mistake
     * is reported and not entered. Parties, ledgers, products and godowns are found by code or name; a party not yet
     * in the system is added.
     *
     * @return array{batch: string, entered: int, errors: list<array{row: int, error: string}>}
     */
    public function import(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', ltrim($csv, "\xEF\xBB\xBF")) ?: [];
        $lines = array_values(array_filter($lines, fn (string $l) => trim($l, " \t,;") !== ''));
        if ($lines === []) {
            throw new BusinessRuleViolation('The file is empty.');
        }
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn ($h) => Str::snake(trim(mb_strtolower((string) $h))), str_getcsv($lines[0], $delimiter));
        $header = array_map(fn ($h) => $h === 'register' || $h === 'book_ref' ? 'register_page' : $h, $header);
        if (! in_array('kind', $header, true) || ! in_array('date', $header, true)) {
            throw new BusinessRuleViolation('The first row must be the column names of the template (kind, date, …). Download the template.');
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            throw new BusinessRuleViolation(sprintf('At most %d rows at a time: split the file.', self::MAX_ROWS));
        }

        $batch = 'IMP-'.now('Asia/Karachi')->format('ymd-His').'-'.Str::upper(Str::random(4));
        $entered = 0;
        $errors = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            $cells = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($header as $n => $column) {
                $row[$column] = trim((string) ($cells[$n] ?? ''));
            }
            try {
                DB::transaction(fn () => $this->record($this->fromRow($row), $batch));
                $entered++;
            } catch (BusinessRuleViolation|InvalidArgumentException $e) {
                $errors[] = ['row' => $i + 2, 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                $errors[] = ['row' => $i + 2, 'error' => 'Could not be entered: check the values in this row.'];
            }
        }
        $this->audit->record('paper_record.imported', null, null, ['batch' => $batch, 'entered' => $entered, 'errors' => count($errors)], [], app(TenantContext::class)->entityId());

        return ['batch' => $batch, 'entered' => $entered, 'errors' => $errors];
    }

    /** The spreadsheet template, with one example row of each kind. */
    public static function template(): string
    {
        $examples = [
            ['kind' => 'opening_party', 'date' => '30/06/2025', 'register_page' => 'Khata 2025, page 12', 'party' => 'Haji Aslam', 'party_phone' => '0300-1234567',
                'party_cnic' => '36302-1234567-1', 'party_address' => 'Grain Market', 'party_city' => 'Lodhran', 'amount' => '250000', 'side' => 'receivable', 'description' => 'Balance brought forward'],
            ['kind' => 'opening_party', 'date' => '30/06/2025', 'register_page' => 'Khata 2025, page 40', 'party' => 'Rana Paddy Traders', 'party_phone' => '0301-7654321',
                'party_city' => 'Kahror Pakka', 'amount' => '480000', 'side' => 'payable'],
            ['kind' => 'opening_ledger', 'date' => '30/06/2025', 'register_page' => 'Cash book 2025, page 1', 'amount' => '85000', 'side' => 'dr', 'account' => 'Cash', 'description' => 'Cash in hand'],
            ['kind' => 'opening_stock', 'date' => '30/06/2025', 'register_page' => 'Stock register, page 3', 'amount' => '1500000', 'product' => 'Paddy 1121', 'godown' => 'Main godown', 'quantity_kg' => '20000'],
            ['kind' => 'purchase', 'date' => '05/07/2025', 'register_page' => 'Purchase register, page 8', 'paper_number' => '112', 'party' => 'Rana Paddy Traders',
                'product' => 'Paddy 1121', 'godown' => 'Main godown', 'quantity_kg' => '12000', 'rate_per_maund' => '3200',
                'vehicle' => 'MNA-21-1234', 'vehicle_type' => 'truck', 'driver_name' => 'Muhammad Akram', 'driver_phone' => '0302-1112233', 'bags' => '300', 'bilty_no' => 'B-778'],
            ['kind' => 'sale', 'date' => '09/07/2025', 'register_page' => 'Sales register, page 2', 'paper_number' => '45', 'party' => 'Haji Aslam', 'amount' => '640000',
                'vehicle' => 'LES-4455', 'driver_name' => 'Ghulam Rasool', 'bags' => '160', 'description' => 'Rice 1121 sella'],
            ['kind' => 'payment', 'date' => '10/07/2025', 'register_page' => 'Cash book 2025, page 6', 'party' => 'Rana Paddy Traders', 'amount' => '200000',
                'cash_bank' => 'Cash', 'payment_mode' => 'cash', 'handled_by' => 'Munshi Iqbal'],
            ['kind' => 'payment', 'date' => '11/07/2025', 'register_page' => 'Bank book 2025, page 2', 'party' => 'Rana Paddy Traders', 'amount' => '300000',
                'cash_bank' => 'Meezan Bank — Current account', 'payment_mode' => 'cheque', 'reference' => '00456789', 'cheque_date' => '11/07/2025', 'party_bank' => 'HBL Kahror Pakka'],
            ['kind' => 'receipt', 'date' => '12/07/2025', 'register_page' => 'Cash book 2025, page 7', 'party' => 'Haji Aslam', 'amount' => '150000',
                'cash_bank' => 'Meezan Bank — Current account', 'payment_mode' => 'ibft', 'reference' => 'TXN 88213', 'party_bank' => 'MCB Lodhran'],
            ['kind' => 'expense', 'date' => '12/07/2025', 'register_page' => 'Cash book 2025, page 7', 'amount' => '3500', 'account' => 'Round off', 'cash_bank' => 'Cash',
                'payment_mode' => 'cash', 'handled_by' => 'Munshi Iqbal', 'description' => 'Diesel for generator'],
            ['kind' => 'journal', 'date' => '15/07/2025', 'register_page' => 'Journal, page 1', 'amount' => '5000', 'account' => 'Brokerage on purchase',
                'credit_account' => 'Rana Paddy Traders', 'description' => 'Brokerage charged to supplier'],
            ['kind' => 'weighment', 'date' => '05/07/2025', 'register_page' => 'Kanta register, page 21', 'paper_number' => '3341', 'party' => 'Rana Paddy Traders',
                'product' => 'Paddy 1121', 'vehicle' => 'MNA-21-1234', 'driver_name' => 'Muhammad Akram', 'bags' => '300', 'gross_kg' => '32500', 'tare_kg' => '12500'],
            ['kind' => 'note', 'date' => '20/07/2025', 'register_page' => 'Lab register, page 4', 'party' => 'Rana Paddy Traders', 'vehicle' => 'MNA-21-1234',
                'description' => 'Moisture 14%, broken 3%'],
        ];
        $rows = [self::COLUMNS, ...array_map(fn (array $e) => array_map(fn (string $c) => $e[$c] ?? '', self::COLUMNS), $examples)];
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);

        return "\xEF\xBB\xBF".stream_get_contents($out);
    }

    /**
     * A spreadsheet row in the form record() takes: names and codes looked up, a missing party added.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function fromRow(array $row): array
    {
        $kind = Str::snake(mb_strtolower($row['kind'] ?? ''));
        $data = ['kind' => $kind, 'date' => $row['date'] ?? '', 'book_ref' => $row['register_page'] ?? null, 'paper_number' => $row['paper_number'] ?? null,
            'amount' => $row['amount'] ?? null, 'side' => mb_strtolower($row['side'] ?? ''), 'description' => $row['description'] ?? null,
            'quantity_kg' => $row['quantity_kg'] ?? null, 'rate_per_maund' => $row['rate_per_maund'] ?? null, 'vehicle' => $row['vehicle'] ?? null,
            'gross_kg' => $row['gross_kg'] ?? null, 'tare_kg' => $row['tare_kg'] ?? null]
            + array_intersect_key($row, array_flip(['party_phone', 'party_cnic', 'party_address', 'party_city', 'payment_mode', 'reference', 'cheque_date',
                'party_bank', 'handled_by', 'vehicle_type', 'driver_name', 'driver_phone', 'bags', 'bilty_no']));
        $data['side'] = match ($data['side']) {
            'debit', 'dr', 'd' => 'dr', 'credit', 'cr', 'c' => 'cr',
            'receivable', 'owes us', 'lena', 'debtor' => 'receivable', 'payable', 'we owe', 'dena', 'creditor' => 'payable',
            default => $data['side'],
        };
        if (filled($row['party'] ?? null)) {
            $data['party_id'] = $this->findOrAddParty($row['party'], $kind, $data['side'])->id;
        }
        foreach (['account' => 'account_id', 'cash_bank' => 'cash_bank_id', 'credit_account' => 'credit_account_id'] as $column => $key) {
            if (filled($row[$column] ?? null)) {
                $data[$key] = $this->findLedger($row[$column])->id;
            }
        }
        if (filled($row['product'] ?? null)) {
            $product = Product::query()->where(fn ($q) => $q->where('code', $row['product'])->orWhereRaw('lower(name) = ?', [mb_strtolower($row['product'])]))->first();
            if ($product !== null) {
                $data['product_id'] = $product->id;
            } elseif ($kind === 'weighment') {
                $data['product_name'] = $row['product'];
            } else {
                throw new BusinessRuleViolation("Product \"{$row['product']}\" is not in the system: add it under Masters → Products first.");
            }
        }
        if (filled($row['godown'] ?? null)) {
            $data['godown_id'] = (Godown::query()->where(fn ($q) => $q->where('code', $row['godown'])->orWhereRaw('lower(name) = ?', [mb_strtolower($row['godown'])]))->first()
                ?? throw new BusinessRuleViolation("Godown \"{$row['godown']}\" is not in the system: add it under Masters → Godowns first."))->id;
        }

        return $data;
    }

    private function findOrAddParty(string $name, string $kind, string $side): Party
    {
        $party = Party::query()->withArchived()->where(fn ($q) => $q->where('code', $name)->orWhereRaw('lower(name) = ?', [mb_strtolower(trim($name))]))->first();
        if ($party !== null) {
            return $party;
        }
        $supplier = in_array($kind, ['purchase', 'payment', 'weighment'], true) || $side === 'payable';
        $name = mb_substr(trim(InputGuard::clean($name)), 0, 150);
        if ($name === '') {
            throw new BusinessRuleViolation('The party name is empty.');
        }

        return Party::query()->create(['name' => $name, 'is_vendor' => $supplier, 'is_customer' => ! $supplier]);
    }

    private function findLedger(string $name): LedgerAccount
    {
        $key = mb_strtolower(trim($name));
        $ledger = LedgerAccount::query()->where(fn ($q) => $q->where('code', $name)->orWhereRaw('lower(name) = ?', [$key]))->first();
        if ($ledger === null && $key === 'cash') {
            $ledger = $this->chart->system('cash');
        }
        if ($ledger === null && ($party = Party::query()->where(fn ($q) => $q->where('code', $name)->orWhereRaw('lower(name) = ?', [$key]))->first()) !== null) {
            $ledger = $this->chart->partyLedger($party);
        }

        return $ledger ?? throw new BusinessRuleViolation("Account \"{$name}\" is not in the chart of accounts: add it under Accounts → Ledger accounts (or Bank accounts) first.");
    }

    private function require(string $kind): void
    {
        $me = $this->actor->account();
        $allowed = match (true) {
            in_array($kind, self::STOCK_KINDS, true) => $me?->hasPermission('stock.adjust'),
            in_array($kind, self::KEPT_KINDS, true) => $me?->hasPermission('ledger.post') || $me?->hasPermission('stock.adjust'),
            default => $me?->hasPermission('ledger.post'),
        };
        if (! $allowed) {
            throw new BusinessRuleViolation(in_array($kind, self::STOCK_KINDS, true) ? "You need 'stock.adjust' for this." : "You need 'ledger.post' for this.");
        }
    }

    private function date(mixed $value, bool $future = false): CarbonImmutable
    {
        $value = trim((string) $value);
        $date = null;
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'j/n/Y', 'j-n-Y'] as $format) {
            try {
                $try = CarbonImmutable::createFromFormat('!'.$format, $value, 'Asia/Karachi');
            } catch (InvalidArgumentException) {
                continue;   // not in this format
            }
            if ($try !== false && $try->format($format) === $value) {
                $date = $try;
                break;
            }
        }
        if ($date === null) {
            throw new BusinessRuleViolation("Date \"{$value}\" is not a date (write it as 31/12/2024).");
        }
        if (! $future && $date->isAfter(now('Asia/Karachi')->endOfDay())) {
            throw new BusinessRuleViolation('An old record cannot be dated in the future.');
        }
        if ($date->year < 1990) {
            throw new BusinessRuleViolation('Check the date: it is before 1990.');
        }

        return $date;
    }

    private function amount(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            $paise = Money::rupees($value)->paise;
        } catch (InvalidArgumentException) {
            throw new BusinessRuleViolation("Amount \"{$value}\" is not a number of rupees.");
        }
        if ($paise < 0) {
            throw new BusinessRuleViolation('Enter the amount without a minus sign; choose the side instead.');
        }

        return $paise;
    }

    private function weight(mixed $value): ?Weight
    {
        $value = str_replace([',', ' '], '', trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{1,11}(\.\d{1,3})?$/', $value) !== 1) {
            throw new BusinessRuleViolation("Weight \"{$value}\" is not a number of kg.");
        }

        return Weight::kg($value);
    }

    private function party(mixed $id, bool $required): ?Party
    {
        if (! filled($id)) {
            if ($required) {
                throw new BusinessRuleViolation('Choose the party.');
            }

            return null;
        }

        return Party::query()->withArchived()->find($id) ?? throw new BusinessRuleViolation('The party was not found.');
    }

    private function ledger(mixed $id, string $missing): LedgerAccount
    {
        $ledger = filled($id) ? LedgerAccount::query()->find($id) : null;

        return $ledger ?? throw new BusinessRuleViolation($missing !== '' ? $missing : 'The account was not found.');
    }

    private function moneyAccount(mixed $id, ?string $mode = null): LedgerAccount
    {
        if (! filled($id) && $mode !== null && $mode !== 'cash') {
            throw new BusinessRuleViolation('Paid by '.self::PAYMENT_MODES[$mode].': choose the bank account the money went through.');
        }
        $ledger = filled($id) ? $this->ledger($id, '') : $this->chart->system('cash');
        if (! in_array($ledger->kind, ['bank', 'cash'], true)) {
            throw new BusinessRuleViolation('Paid or received through: choose cash or a bank account.');
        }
        if ($mode === 'cash' && $ledger->kind === 'bank') {
            throw new BusinessRuleViolation('Paid in cash: choose Cash, not a bank account.');
        }
        if (in_array($mode, self::BANK_MODES, true) && $ledger->kind === 'cash') {
            throw new BusinessRuleViolation(self::PAYMENT_MODES[$mode].' goes through a bank: choose the bank account.');
        }

        return $ledger;
    }

    /**
     * The vehicle the goods came or went in, as on the paper: number, type, driver, bags, bilty.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function vehicleDetails(array $data, \Closure $text): array
    {
        $number = $text('vehicle', 30);
        $type = $text('vehicle_type', 20);
        $type = $type === null ? null : Str::snake(mb_strtolower($type));
        if ($type !== null && ! in_array($type, Vehicle::TYPES, true)) {
            throw new BusinessRuleViolation(sprintf('Vehicle type "%s" is not one of: %s.', $type, implode(', ', Vehicle::TYPES)));
        }
        $bags = $text('bags', 10);
        if ($bags !== null && preg_match('/^\d{1,7}$/', $bags) !== 1) {
            throw new BusinessRuleViolation("Bags \"{$bags}\" is not a whole number.");
        }
        $driverPhone = $text('driver_phone', 20);
        if ($driverPhone !== null && PakistanIds::normalizeMobile($driverPhone) === null) {
            throw new BusinessRuleViolation("Driver's phone \"{$driverPhone}\" is not a mobile number (e.g. 0300-1234567).");
        }
        if ($number !== null) {
            $plate = PakistanIds::normalizeVehicleNumber($number);
            $number = PakistanIds::isValidVehicleNumber($plate) ? PakistanIds::formatVehicleNumber($plate) : $number;
        }

        return ['vehicle' => $number, 'vehicle_type' => $type, 'driver_name' => $text('driver_name', 120),
            'driver_phone' => $driverPhone === null ? null : PakistanIds::formatMobile(PakistanIds::normalizeMobile($driverPhone)),
            'bags' => $bags, 'bilty_no' => $text('bilty_no', 40)];
    }

    /**
     * How the money moved, as on the paper: mode, cheque or transaction no., cheque date, the party's bank, who paid or received it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function paymentDetails(string $kind, array $data, \Closure $text): array
    {
        if (! in_array($kind, self::PAYMENT_KINDS, true)) {
            return [];
        }
        $mode = $text('payment_mode', 20);
        if ($mode !== null) {
            $mode = Str::snake(mb_strtolower($mode));
            $mode = match ($mode) {
                'online', 'transfer', 'bank_transfer' => 'ibft', 'jazzcash', 'easypaisa', 'jazz_cash' => 'mobile', 'po' => 'pay_order', default => $mode,
            };
            if (! isset(self::PAYMENT_MODES[$mode])) {
                throw new BusinessRuleViolation(sprintf('Payment mode "%s" is not one of: %s.', $mode, implode(', ', array_keys(self::PAYMENT_MODES))));
            }
        }
        $chequeDate = $text('cheque_date', 20);
        if ($chequeDate !== null) {
            $chequeDate = $this->date($chequeDate, future: true)->toDateString();
        }
        if (in_array($mode, ['cheque', 'pay_order', 'ibft', 'rtgs', 'raast', 'mobile'], true) && $text('reference', 60) === null) {
            throw new BusinessRuleViolation(sprintf('Paid by %s: enter its %s.', self::PAYMENT_MODES[$mode], $mode === 'cheque' || $mode === 'pay_order' ? 'number' : 'transaction ID'));
        }

        return ['payment_mode' => $mode, 'reference' => $text('reference', 60), 'cheque_date' => $chequeDate,
            'party_bank' => $text('party_bank', 120), 'handled_by' => $text('handled_by', 120)];
    }

    /**
     * Fills what the paper tells about the party where the system has nothing yet (never overwrites), and keeps a
     * copy of it as on the paper.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function partyDetails(Party $party, array $data): array
    {
        $clean = fn (string $key, int $max) => ($v = trim(InputGuard::clean((string) ($data[$key] ?? '')))) === '' ? null : mb_substr($v, 0, $max);
        $phone = $clean('party_phone', 20);
        if ($phone !== null && PakistanIds::normalizeMobile($phone) === null && preg_match('/^[0-9+\-\s]{7,20}$/', $phone) !== 1) {
            throw new BusinessRuleViolation("Party's phone \"{$phone}\" is not a phone number.");
        }
        $cnic = $clean('party_cnic', 20);
        if ($cnic !== null && ! PakistanIds::isValidCnic((string) PakistanIds::digits($cnic))) {
            throw new BusinessRuleViolation("Party's CNIC \"{$cnic}\" is not a CNIC (13 digits, e.g. 36302-1234567-1).");
        }
        $fill = array_filter(['phone' => $phone, 'cnic' => $cnic, 'address' => $clean('party_address', 300), 'city' => $clean('party_city', 80)],
            fn ($v, $field) => $v !== null && blank($party->getAttribute($field)), ARRAY_FILTER_USE_BOTH);
        if ($fill !== []) {
            $party->fill($fill)->save();
        }

        return array_filter(['name' => $party->name, 'code' => $party->code, 'phone' => PakistanIds::formatMobile($party->phone) ?? $party->phone,
            'cnic' => $party->cnic ? '*********'.substr($party->cnic, -4) : null, 'address' => $party->address, 'city' => $party->city],
            fn ($v) => filled($v));
    }

    /**
     * The vehicle in the Vehicles list: found by its number, or added (with the driver) so it is known from now on.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     */
    private function linkVehicle(array $details, ?Party $party): array
    {
        $plate = PakistanIds::normalizeVehicleNumber((string) $details['vehicle']);
        if (! PakistanIds::isValidVehicleNumber($plate)) {
            return [];   // kept as written; not a number the Vehicles list takes
        }
        $vehicle = Vehicle::query()->withArchived()->where('registration_no', $plate)->first();
        $driver = null;
        if (filled($details['driver_name'] ?? null)) {
            $phone = PakistanIds::normalizeMobile($details['driver_phone'] ?? null);
            $driver = Driver::query()->whereRaw('lower(name) = ?', [mb_strtolower((string) $details['driver_name'])])
                ->when($phone, fn ($q) => $q->where(fn ($q) => $q->where('phone', $phone)->orWhereNull('phone')))->first()
                ?? Driver::query()->create(['name' => $details['driver_name'], 'phone' => $phone, 'status' => 'active']);
        }
        if ($vehicle === null) {
            $vehicle = Vehicle::query()->create(['registration_no' => $plate, 'vehicle_type' => $details['vehicle_type'] ?? 'truck',
                'owner_party_id' => $party?->id, 'default_driver_id' => $driver?->id, 'status' => 'active']);
        } elseif ($driver !== null && $vehicle->default_driver_id === null) {
            $vehicle->forceFill(['default_driver_id' => $driver->id])->save();
        }

        return array_filter(['vehicle_id' => $vehicle->id, 'driver_id' => $driver?->id]);
    }

    private function product(mixed $id): Product
    {
        return Product::query()->find($id) ?? throw new BusinessRuleViolation('The product was not found.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Product, 1: Godown}
     */
    private function place(array $data): array
    {
        if (! filled($data['product_id'] ?? null) || ! filled($data['godown_id'] ?? null)) {
            throw new BusinessRuleViolation('Choose the product and the godown.');
        }

        return [$this->product($data['product_id']), Godown::query()->find($data['godown_id']) ?? throw new BusinessRuleViolation('The godown was not found.')];
    }
}
