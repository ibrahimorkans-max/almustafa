<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\DailyWages\DailyWages;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\Expense;
use App\Models\Accounts\Voucher;
use App\Models\Accounts\VoucherLine;
use App\Models\Claims\Claim;
use App\Models\DailyWages\DailyWageSheet;
use App\Models\Entity;
use App\Models\Hr\SalaryVoucher;
use App\Models\Inventory\Requisition;
use App\Models\LoginAccount;
use App\Models\Procurement\Sauda;
use App\Models\Production\ProductionBatch;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\QuickBill;
use App\Models\Sales\QuickBillLine;
use App\Support\Values\AmountInWords;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Printed records for the screens that had none: any accounting voucher, expense / income entry,
 * claim, sauda (purchase or sale contract), dispatch order, stores issue slip and production batch.
 * Accounts papers carry a signature box for the second person (checked / approved by), with the
 * names the system knows printed above the lines.
 */
final class RecordDocuments
{
    /** Printable types: key => [model, label, permission needed to print]. */
    public const TYPES = [
        'voucher' => [Voucher::class, 'Voucher', 'ledger.view'],
        'expense' => [Expense::class, 'Expense voucher', 'ledger.view'],
        'claim' => [Claim::class, 'Claim note', 'claim.view'],
        'sauda' => [Sauda::class, 'Sauda contract', 'deal.view'],
        'dispatch' => [DispatchOrder::class, 'Dispatch order', 'sales.view'],
        'requisition' => [Requisition::class, 'Stores issue slip', 'stock.view'],
        'batch' => [ProductionBatch::class, 'Production batch report', 'production.view'],
        'daily_wage' => [DailyWageSheet::class, 'Daily wage slip', 'wages.daily.manage'],
        'quick_bill' => [QuickBill::class, 'Bill', 'sales.view'],
        'salary_voucher' => [SalaryVoucher::class, 'Salary voucher', 'hr.payroll.run'],
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PdfRenderer $pdf,
    ) {}

    public function pdf(string $type, Model $record): string
    {
        $doc = match ($type) {
            'voucher' => $this->voucher($record),
            'expense' => $this->expense($record),
            'claim' => $this->claim($record),
            'sauda' => $this->sauda($record),
            'dispatch' => $this->dispatch($record),
            'requisition' => $this->requisition($record),
            'batch' => $this->batch($record),
            'daily_wage' => $this->dailyWage($record),
            'quick_bill' => $this->quickBill($record),
            'salary_voucher' => $this->salaryVoucher($record),
            default => throw new BusinessRuleViolation("Unknown document {$type}."),
        };
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($record->getAttribute('entity_id')));

        // A dispatch order and a sale sauda are selling documents: the sales logo.
        $sales = $type === 'dispatch' || ($type === 'quick_bill' && $record->getAttribute('kind') === 'sale') || ($type === 'sauda' && $record->getAttribute('direction') === 'sale');

        return $this->pdf->render('documents.record', ['doc' => $doc, 'title' => $doc['title']] + ($sales ? ['brand_use' => 'sales'] : []), $entity, ($doc['small'] ?? false) ? 'A5' : 'A4');
    }

    /** @return array<string, mixed> */
    private function voucher(Model $v): array
    {
        /** @var Voucher $v */
        $v->loadMissing('lines.account');
        $lines = $v->lines->sortBy('line_no');

        return [
            'title' => mb_strtoupper(str_replace('_', ' ', $v->type)).' VOUCHER', 'titleUr' => 'واؤچر', 'small' => false,
            'fields' => [['Voucher no.', $v->number], ['Date', $v->voucher_date->format('d M Y')], ['Narration', $v->narration ?? '—']],
            'table' => [
                'columns' => ['#', 'Account', 'Memo', 'Debit (Rs)', 'Credit (Rs)'], 'right' => [3, 4],
                'rows' => $lines->map(fn (VoucherLine $l) => [$l->line_no, $l->account->code.' · '.$l->account->name, $l->memo ?? '',
                    $l->dr_paise ? Money::paise($l->dr_paise)->grouped() : '', $l->cr_paise ? Money::paise($l->cr_paise)->grouped() : ''])->values()->all(),
                'totals' => [['Total', Money::paise($v->total_paise)->format()]],
            ],
            'words' => AmountInWords::rupees($v->total_paise),
            'signs' => [['Prepared by', 'تیار کنندہ', $this->name($v->created_by)], ['Checked by (second person)', 'جانچ کنندہ'], ['Approved by', 'منظور کنندہ']],
        ];
    }

    /** @return array<string, mixed> */
    private function expense(Model $x): array
    {
        /** @var Expense $x */
        if ($x->status !== Expense::POSTED) {
            throw new BusinessRuleViolation('Only a posted entry can be printed.');
        }
        $x->loadMissing(['category', 'cashLedger', 'party']);
        $income = $x->kind === 'income';

        return [
            'title' => $income ? 'INCOME RECEIPT' : 'EXPENSE VOUCHER', 'titleUr' => $income ? 'آمدنی رسید' : 'اخراجات واؤچر', 'small' => true,
            'fields' => [['Number', $x->number], ['Date', $x->entry_date->format('d M Y')], ['Category', $x->category->name ?? '—'],
                [$income ? 'Received into' : 'Paid from', $x->cashLedger->name ?? 'On credit'], ['Party', $x->party->name ?? '—'], ['Description', $x->description]],
            'table' => ['columns' => ['Description', 'Amount (Rs)'], 'right' => [1], 'rows' => [[$x->description, Money::paise($x->amount_paise)->grouped()]],
                'totals' => [['Total', Money::paise($x->amount_paise)->format()]]],
            'words' => AmountInWords::rupees($x->amount_paise),
            'signs' => [['Prepared by', 'تیار کنندہ', $this->name($x->created_by)], ['Checked & posted by (second person)', 'جانچ کنندہ', $x->posted_by !== $x->created_by ? $this->name($x->posted_by) : null],
                [$income ? 'Paid by' : 'Received by', $income ? 'ادا کنندہ' : 'وصول کنندہ']],
        ];
    }

    /** @return array<string, mixed> */
    private function claim(Model $c): array
    {
        /** @var Claim $c */
        $c->loadMissing('party');

        return [
            'title' => 'CLAIM NOTE', 'titleUr' => 'کلیم نوٹ', 'small' => true,
            'fields' => [['Claim no.', $c->number], ['Date', $c->created_at->timezone('Asia/Karachi')->format('d M Y')], ['Party', $c->party->name ?? '—'],
                ['Against', ($c->document_type === Claim::SALES ? 'Sales invoice' : 'Purchase bill')], ['Type', str_replace('_', ' ', $c->type)], ['Status', $c->status]],
            'table' => ['columns' => ['Item', 'Amount (Rs)'], 'right' => [1], 'rows' => array_values(array_filter([
                ['Claimed', Money::paise($c->claimed_paise)->grouped()],
                $c->settled_paise !== null ? ['Settled', Money::paise($c->settled_paise)->grouped()] : null,
            ]))],
            'notes' => [$c->description],
            'signs' => [['Raised by', 'دائر کنندہ', $this->name($c->created_by)], ['Checked by (second person)', 'جانچ کنندہ'], ['Party', 'فریق']],
        ];
    }

    /** @return array<string, mixed> */
    private function sauda(Model $s): array
    {
        /** @var Sauda $s */
        $s->loadMissing(['party', 'broker', 'product']);
        $sale = $s->direction === 'sale';
        $maund = bcdiv((string) $s->quantity_kg, '40', 2);

        return [
            'title' => $sale ? 'SALE CONTRACT (SAUDA)' : 'PURCHASE CONTRACT (SAUDA)', 'titleUr' => 'سودا', 'small' => false,
            'fields' => [['Sauda no.', $s->number], ['Status', str_replace('_', ' ', $s->status)], [$sale ? 'Buyer' : 'Seller', $s->party->name], ['Broker', $s->broker->name ?? '—'],
                ['Product', $s->product->name], ['Delivery', ($s->delivery_from?->format('d M Y') ?? '—').' to '.($s->delivery_to?->format('d M Y') ?? '—')],
                ['Payment', $s->payment_days.' days'], ['Tolerance', rtrim(rtrim(number_format($s->tolerance_bp / 100, 2), '0'), '.').' %']],
            'table' => ['columns' => ['Quantity (kg)', 'Quantity (maund)', 'Rate per maund', 'Value (Rs)'], 'right' => [0, 1, 2, 3], 'rows' => [[
                number_format((float) $s->quantity_kg, 0), $maund, Money::paise($s->rate_paise_per_maund)->format(),
                Money::paise((int) round((float) $maund * $s->rate_paise_per_maund))->grouped(),
            ]]],
            'notes' => array_values(array_filter([
                $s->quality_terms ? 'Quality: '.collect($s->quality_terms)->map(fn ($v, $k) => str_replace('_', ' ', (string) $k).' '.rtrim(rtrim(number_format(((int) $v) / 100, 2), '0'), '.').' %')->implode(', ') : null,
                $s->brokerage_paise_per_maund ? 'Brokerage: '.Money::paise($s->brokerage_paise_per_maund)->format().' per maund' : null,
            ])),
            'signs' => [['Made by', 'تیار کنندہ', $this->name($s->created_by)], ['Approved by (second person)', 'منظور کنندہ', $this->name($s->approved_by)], [$sale ? 'Buyer' : 'Seller', $sale ? 'خریدار' : 'فروخت کنندہ']],
        ];
    }

    /** @return array<string, mixed> */
    private function dispatch(Model $d): array
    {
        /** @var DispatchOrder $d */
        $d->loadMissing(['party', 'product', 'godown', 'sauda', 'gateEntry']);

        return [
            'title' => 'DISPATCH ORDER', 'titleUr' => 'ڈسپیچ آرڈر', 'small' => true,
            'fields' => [['Order no.', $d->number], ['Sauda', $d->sauda->number], ['Buyer', $d->party->name], ['Product', $d->product->name],
                ['From godown', $d->godown->name], ['Vehicle', $d->gateEntry->registration_no ?? '—'], ['Status', str_replace('_', ' ', $d->status)]],
            'table' => ['columns' => ['Quantity (kg)', 'Bags', 'Bag size (kg)', 'Estimated value (Rs)'], 'right' => [0, 1, 2, 3], 'rows' => [[
                number_format((float) $d->quantity_kg, 0), $d->bag_count ?? '—', $d->bag_weight_kg ?? '—', Money::paise($d->estimated_value_paise)->grouped(),
            ]]],
            'notes' => array_values(array_filter([$d->note])),
            'signs' => [['Ordered by', 'آرڈر کنندہ', $this->name($d->created_by)], ['Store keeper', 'اسٹور کیپر'], ['Driver / buyer', 'ڈرائیور']],
        ];
    }

    /**
     * A quick bill: date and time, the party (or walk-in customer), the vehicle, every line with its weights, deductions,
     * rate per maund and amount, the charges, the total, what is paid and the balance (PAID / UNPAID).
     *
     * @return array<string, mixed>
     */
    private function quickBill(Model $b): array
    {
        /** @var QuickBill $b */
        $b->loadMissing(['party', 'lines', 'charges', 'payments', 'sauda']);
        [$title, $titleUr] = match ($b->kind) {
            'purchase' => ['PURCHASE BILL', 'خریداری بل'],
            'exchange' => ['HISAAB BILL (RICE FOR PADDY)', 'حساب بل'],
            'milling' => ['MILLING BILL (MAZDOORI)', 'پسائی بل'],
            'weighing' => ['WEIGHING SLIP', 'کنڈا پرچی'],
            default => ['SALE BILL', 'فروخت بل'],
        };
        $status = $b->status === QuickBill::CANCELLED ? 'CANCELLED — '.$b->cancel_reason : ['paid' => 'PAID', 'partial' => 'PARTLY PAID', 'unpaid' => 'UNPAID'][$b->paymentStatus()];
        $rows = $b->lines->map(fn (QuickBillLine $l) => [
            ($l->direction === 'in' ? '(In) ' : ($l->direction === 'out' && $b->kind === 'exchange' ? '(Out) ' : '')).$l->description.($l->variety ? " — {$l->variety}" : ''),
            $l->bags ?? '—',
            number_format((float) $l->gross_kg, 2),
            number_format((float) bcadd($l->tare_kg, $l->deductionKg(), 3), 2).((float) $l->deduction_per_bag_kg > 0 ? " ({$l->deduction_per_bag_kg}/bag)" : ''),
            number_format((float) $l->net_kg, 2),
            bcdiv($l->net_kg, '40', 2),
            Money::paise($l->rate_paise_per_maund)->grouped(),
            Money::paise($l->amount_paise)->grouped(),
        ])->values()->all();
        foreach ($b->charges as $c) {
            $rows[] = [($c->effect === 'add' ? '+ ' : '− ').$c->label, '', '', '', '', '', '', ($c->effect === 'less' ? '-' : '').Money::paise($c->amount_paise)->grouped()];
        }
        $fields = [['Bill no.', $b->number], ['Date and time', $b->billed_at->timezone('Asia/Karachi')->format('d M Y, h:i A')],
            [$b->kind === 'purchase' ? 'Seller' : 'Party / customer', $b->customer().($b->customer_phone ? " ({$b->customer_phone})" : '')],
            ['Vehicle', trim((QuickBill::VEHICLES[$b->vehicle_type] ?? '').' '.($b->vehicle_no ?? '')) ?: '—'], ['Payment', $status]];
        if ($b->sauda !== null) {
            $fields[] = ['Sauda', $b->sauda->number];
        }
        if ($b->kind === 'weighing') {
            $fields[] = ['Weights', "{$b->first_weight_kg} kg / {$b->second_weight_kg} kg — net ".ltrim(bcsub((string) $b->first_weight_kg, (string) $b->second_weight_kg, 3), '-').' kg'];
        }
        $totals = [['Bill total'.($b->net_paise < 0 ? ' (mill pays)' : ''), Money::paise(abs($b->net_paise))->format()], ['Paid', Money::paise($b->paid_paise)->format()],
            ['Balance', Money::paise($b->balancePaise())->format()]];

        return [
            'title' => $title, 'titleUr' => $titleUr, 'small' => count($rows) <= 6,
            'fields' => $fields,
            'table' => ['columns' => ['Item', 'Bags', 'Weight (kg)', 'Less (kg)', 'Net (kg)', 'Maund', 'Rate / maund (Rs)', 'Amount (Rs)'], 'right' => [1, 2, 3, 4, 5, 6, 7],
                'rows' => $rows, 'totals' => $totals],
            'words' => AmountInWords::rupees(abs($b->net_paise)),
            'notes' => array_values(array_filter([$b->note, ...$b->payments->map(fn ($p) => 'Paid '.Money::paise($p->amount_paise)->format().' by '
                .(QuickBill::PAYMENT_MODES[$p->mode] ?? $p->mode).($p->reference ? " {$p->reference}" : '').' on '.$p->paid_at->timezone('Asia/Karachi')->format('d M Y, h:i A'))->all()])),
            'signs' => [['Made by', 'تیار کنندہ', $this->name($b->created_by)], ['Party', 'فریق'], ['Accounts', 'اکاؤنٹس']],
        ];
    }

    /** @return array<string, mixed> */
    private function salaryVoucher(Model $s): array
    {
        /** @var SalaryVoucher $s */
        $s->loadMissing(['employee.person', 'employee.designation', 'ledger', 'advance']);
        $rows = [[SalaryVoucher::KINDS[$s->kind].($s->month ? ' — '.CarbonImmutable::createFromFormat('!Y-m', $s->month)->format('F Y') : '').($s->days ? " ({$s->days} days)" : ''),
            Money::paise($s->gross_paise)->grouped()]];
        if ($s->advance_paise > 0) {
            $rows[] = ['Less: advance / loan instalment'.($s->advance ? " ({$s->advance->number})" : ''), '-'.Money::paise($s->advance_paise)->grouped()];
        }
        if ($s->other_deduction_paise > 0) {
            $rows[] = ['Less: '.($s->other_deduction_note ?: 'other deduction'), '-'.Money::paise($s->other_deduction_paise)->grouped()];
        }

        return [
            'title' => 'SALARY VOUCHER', 'titleUr' => 'تنخواہ واؤچر', 'small' => true,
            'fields' => [['Voucher no.', $s->number], ['Date and time', $s->paid_at->timezone('Asia/Karachi')->format('d M Y, h:i A')],
                ['Employee', $s->employee->code.' — '.$s->employee->name()], ['Designation', $s->employee->designation->name ?? '—'],
                ['Paid by', (SalaryVoucher::MODES[$s->mode] ?? $s->mode).($s->reference ? " {$s->reference}" : '').' · '.$s->ledger->name]],
            'table' => ['columns' => ['Description', 'Amount (Rs)'], 'right' => [1], 'rows' => $rows,
                'totals' => [['Net paid', Money::paise($s->net_paise)->format()]]],
            'words' => AmountInWords::rupees($s->net_paise),
            'notes' => array_values(array_filter([$s->note])),
            'signs' => [['Prepared by', 'تیار کنندہ', $this->name($s->created_by)], ['Approved by', 'منظور کنندہ'], ['Received by (signature / thumb)', 'وصول کنندہ']],
        ];
    }

    /** @return array<string, mixed> */
    private function requisition(Model $r): array
    {
        /** @var Requisition $r */
        $r->loadMissing(['product', 'fromGodown', 'toGodown', 'toBatch']);

        return [
            'title' => 'STORES ISSUE SLIP', 'titleUr' => 'اسٹور اجراء پرچی', 'small' => true,
            'fields' => [['Requisition no.', $r->number], ['Status', $r->status], ['Product', $r->product->name], ['From', $r->fromGodown->name],
                ['To', $r->toGodown->name ?? ($r->toBatch !== null ? 'Batch '.$r->toBatch->number : '—')], ['Issued', $r->issued_at?->timezone('Asia/Karachi')->format('d M Y H:i') ?? '—']],
            'table' => ['columns' => ['Product', 'Quantity (kg)', 'Quantity (maund)'], 'right' => [1, 2],
                'rows' => [[$r->product->name, number_format((float) $r->quantity_kg, 0), bcdiv((string) $r->quantity_kg, '40', 2)]]],
            'notes' => array_values(array_filter([$r->purpose, $r->decision_note])),
            'signs' => [['Requested by', 'طلب کنندہ', $this->name($r->created_by)], ['Approved by', 'منظور کنندہ', $this->name($r->approved_by)], ['Received by', 'وصول کنندہ']],
        ];
    }

    /** @return array<string, mixed> */
    private function batch(Model $b): array
    {
        /** @var ProductionBatch $b */
        $b->loadMissing(['paddy', 'supervisor.person']);
        $outturn = (array) ($b->yield_snapshot['outturn'] ?? []);

        return [
            'title' => 'PRODUCTION BATCH REPORT', 'titleUr' => 'پیداواری رپورٹ', 'small' => false,
            'fields' => [['Batch', $b->number], ['Status', $b->status], ['Paddy', $b->paddy->name], ['Line', $b->line ?? '—'],
                ['Opened', $b->opened_at->timezone('Asia/Karachi')->format('d M Y H:i')], ['Closed', $b->closed_at?->timezone('Asia/Karachi')->format('d M Y H:i') ?? '—'],
                ['Input (kg)', number_format((float) $b->input_kg, 0)], ['Output (kg)', number_format((float) $b->output_kg, 0)]],
            'table' => $outturn === [] ? null : ['columns' => ['Output', 'kg', 'Share %', 'Standard %', 'Within standard'], 'right' => [1, 2, 3],
                'rows' => array_map(fn ($row) => [(string) ($row['product'] ?? ''), (string) ($row['kg'] ?? ''), isset($row['bp']) ? number_format(((int) $row['bp']) / 100, 2) : '',
                    isset($row['expected_bp']) ? number_format(((int) $row['expected_bp']) / 100, 2) : '', ($row['out_of_range'] ?? false) ? 'NO' : 'yes'], array_values(array_filter($outturn, 'is_array')))],
            'notes' => array_values(array_filter([$b->notes])),
            'signs' => [['Supervisor', 'سپروائزر', $b->supervisor?->person->full_name ?? $b->supervisor?->username], ['Checked by (second person)', 'جانچ کنندہ'], ['Mill manager', 'مل مینیجر']],
        ];
    }

    /** @return array<string, mixed> */
    private function dailyWage(Model $s): array
    {
        /** @var DailyWageSheet $s */
        if ($s->status === DailyWageSheet::CANCELLED) {
            throw new BusinessRuleViolation('A cancelled wage sheet is not printed.');
        }
        $s->loadMissing(['group', 'category', 'paidFrom']);
        $qty = rtrim(rtrim((string) $s->quantity, '0'), '.');
        $rows = [[$s->category->name, (string) $s->workers, $qty.' '.$s->unit, Money::paise($s->rate_paise)->grouped(), Money::paise($s->gross_paise)->grouped()]];
        $totals = [];
        if ($s->extra_paise) {
            $totals[] = ['Extra (overtime, bonus)', Money::paise($s->extra_paise)->grouped()];
        }
        if ($s->deduction_paise) {
            $totals[] = ['Less (advance, food)', '- '.Money::paise($s->deduction_paise)->grouped()];
        }
        $totals[] = ['To pay', Money::paise($s->net_paise)->format()];

        return [
            'title' => 'DAILY WAGE SLIP', 'titleUr' => 'یومیہ اجرت پرچی', 'small' => true,
            'fields' => [['Number', $s->number], ['Work date', $s->work_date->format('d M Y')],
                [$s->group !== null ? 'Labour group' : 'Worker', $s->who()], ['Status', DailyWageSheet::STATUSES[$s->status] ?? $s->status],
                ['Paid from', $s->paidFrom->name ?? 'Not paid yet'], ['Worked out as', DailyWages::explain($s)]],
            'table' => ['columns' => ['Work', 'Workers', 'Quantity', 'Rate (Rs)', 'Wages (Rs)'], 'right' => [1, 2, 3, 4], 'rows' => $rows, 'totals' => $totals],
            'words' => AmountInWords::rupees($s->net_paise),
            'notes' => array_values(array_filter([$s->worker_names !== null ? 'Workers: '.$s->worker_names : null, $s->description])),
            'signs' => [['Prepared by', 'تیار کنندہ', $this->name($s->created_by)], ['Checked & paid by (second person)', 'جانچ کنندہ', $this->name($s->paid_by ?? $s->posted_by)],
                ['Received by (group leader) — sign or thumb', 'وصول کنندہ (انگوٹھا)', $s->group !== null ? $s->group->leader_name : $s->worker_name]],
        ];
    }

    private function name(?string $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }
        $a = $this->tenant->runAsSystem(fn () => LoginAccount::query()->with('person')->find($accountId));

        return $a === null ? null : ($a->person->full_name ?? $a->username);
    }
}
