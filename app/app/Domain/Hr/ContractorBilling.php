<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Hr\Attendance\AttendanceCalculator;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\ContractorBill;
use App\Models\Hr\ContractorBillLine;
use App\Models\Hr\Employee;
use App\Models\Hr\PieceWorkEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Labour contractor bills from the records, not from the contractor's claim: man-days are the
 * attendance of the contractor's workers (present days, half days as 0.5, as calculated for payroll),
 * piece work is the piece-work entries of those workers. Optional provincial sales tax on services is
 * added and income tax under s. 153(1)(b) withheld. A second person approves; approval posts
 * Dr Contract labour / Cr contractor and Cr withholding tax payable. Payment goes through the existing
 * party payments. Periods of one contractor cannot overlap.
 */
final class ContractorBilling
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly DocumentNumbers $numbers,
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly AttendanceCalculator $attendance,
    ) {}

    /**
     * Links workers to a contractor (null = the mill's own staff again).
     *
     * @param  list<string>  $employeeIds
     */
    public function assignWorkers(?Party $contractor, array $employeeIds): int
    {
        $this->require('hr.employee.edit');
        if ($contractor !== null && ! $contractor->is_vendor) {
            throw new BusinessRuleViolation('A labour contractor must be a vendor party.');
        }
        $n = 0;
        foreach (Employee::query()->whereKey($employeeIds)->get() as $e) {
            $e->forceFill(['contractor_party_id' => $contractor?->id])->save();
            $n++;
        }
        $this->audit->record('hr.contractor.workers_assigned', $contractor, null, null, ['employees' => $employeeIds]);

        return $n;
    }

    /**
     * @param  int|array<string, int>  $rate  man_days: paise per man-day; piece: paise per unit, by unit (bag, maund …)
     */
    public function prepare(Party $contractor, CarbonImmutable $from, CarbonImmutable $to, string $basis, int|array $rate, int $salesTaxBp = 0, int $whtBp = 0, ?string $note = null): ContractorBill
    {
        $who = $this->require('hr.payroll.run');
        if (! $contractor->is_vendor) {
            throw new BusinessRuleViolation('A labour contractor must be a vendor party.');
        }
        if (! array_key_exists($basis, ContractorBill::BASES)) {
            throw new BusinessRuleViolation('Bill on man-days or on piece work.');
        }
        if ($to->lessThan($from) || $from->diffInDays($to) > 62 || $to->greaterThanOrEqualTo(CarbonImmutable::now('Asia/Karachi')->startOfDay())) {
            throw new BusinessRuleViolation('The period must be up to two months and already over.');
        }
        if ($salesTaxBp < 0 || $salesTaxBp > 3000 || $whtBp < 0 || $whtBp > 3000) {
            throw new BusinessRuleViolation('Tax rates must be between 0 and 30 %.');
        }
        $overlap = ContractorBill::query()->where('party_id', $contractor->id)->where('status', '!=', 'rejected')
            ->where('period_from', '<=', $to->toDateString())->where('period_to', '>=', $from->toDateString())->first();
        if ($overlap !== null) {
            throw new BusinessRuleViolation("Bill {$overlap->number} already covers part of this period.");
        }
        $workers = Employee::query()->with('person')->where('contractor_party_id', $contractor->id)->orderBy('code')->get();
        if ($workers->isEmpty()) {
            throw new BusinessRuleViolation('No workers are linked to this contractor.');
        }
        $lines = $basis === 'man_days' ? $this->manDays($workers->all(), $from, $to, (int) $rate) : $this->piece($workers->all(), $from, $to, (array) $rate);
        if ($lines === []) {
            throw new BusinessRuleViolation('The workers have no '.($basis === 'man_days' ? 'attendance' : 'piece work').' in this period.');
        }
        $gross = array_sum(array_column($lines, 'amount_paise'));
        $tax = intdiv($gross * $salesTaxBp + 5000, 10000);
        // Withholding is on the gross amount of the services (sales tax excluded).
        $wht = intdiv($gross * $whtBp + 5000, 10000);

        return DB::transaction(function () use ($contractor, $from, $to, $basis, $lines, $gross, $salesTaxBp, $tax, $whtBp, $wht, $note, $who): ContractorBill {
            $bill = ContractorBill::query()->create([
                'number' => $this->numbers->next(DocumentNumbers::CONTRACTOR_BILL, $to), 'party_id' => $contractor->id,
                'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(), 'basis' => $basis, 'gross_paise' => $gross,
                'sales_tax_bp' => $salesTaxBp, 'sales_tax_paise' => $tax, 'wht_bp' => $whtBp, 'wht_paise' => $wht,
                'payable_paise' => $gross + $tax - $wht, 'note' => $note, 'prepared_by' => $who->id,
            ]);
            foreach ($lines as $l) {
                ContractorBillLine::query()->create($l + ['contractor_bill_id' => $bill->id, 'created_at' => now()]);
            }
            $this->audit->record('hr.contractor.bill_prepared', $bill, null, null, ['gross_paise' => $gross, 'lines' => count($lines)]);

            return $bill;
        });
    }

    public function approve(ContractorBill $bill, ?string $note = null): ContractorBill
    {
        $who = $this->require('hr.payroll.approve');

        return DB::transaction(function () use ($bill, $who, $note): ContractorBill {
            $b = ContractorBill::query()->with('party')->lockForUpdate()->findOrFail($bill->id);
            if ($b->status !== 'draft') {
                throw new BusinessRuleViolation('Only a draft bill can be approved.');
            }
            if ($b->prepared_by === $who->id) {
                throw new BusinessRuleViolation('A different person must approve the bill you prepared.');
            }
            $period = $b->period_from->format('d M').'–'.$b->period_to->format('d M Y');
            $lines = [
                ['account' => $this->chart->system('contract_labour'), 'dr' => $b->gross_paise + $b->sales_tax_paise, 'memo' => "{$b->number} {$period}"],
                ['account' => $this->chart->partyLedger($b->party), 'cr' => $b->payable_paise, 'memo' => $b->number],
            ];
            if ($b->wht_paise > 0) {
                $lines[] = ['account' => $this->chart->system('wht_payable'), 'cr' => $b->wht_paise, 'memo' => "{$b->number} s. 153(1)(b) {$b->party->name}"];
            }
            $voucher = $this->books->post('journal', CarbonImmutable::parse($b->period_to->toDateString(), 'Asia/Karachi'), $lines,
                "Labour contractor {$b->party->name}: {$b->number} ({$period})", ['source_type' => 'contractor_bill', 'source_id' => $b->id]);
            $b->forceFill(['status' => 'approved', 'approved_by' => $who->id, 'approved_at' => now(), 'decision_note' => $note, 'voucher_id' => $voucher->id])->save();
            $this->audit->record('hr.contractor.bill_approved', $b, null, null, ['voucher' => $voucher->number]);

            return $b;
        });
    }

    public function reject(ContractorBill $bill, string $reason): ContractorBill
    {
        $this->require('hr.payroll.approve');
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for refusing.');
        }
        $b = ContractorBill::query()->findOrFail($bill->id);
        if ($b->status !== 'draft') {
            throw new BusinessRuleViolation('Only a draft bill can be refused.');
        }
        $b->forceFill(['status' => 'rejected', 'decision_note' => trim($reason)])->save();
        $this->audit->record('hr.contractor.bill_rejected', $b, null, null, ['reason' => trim($reason)]);

        return $b;
    }

    /**
     * @param  list<Employee>  $workers
     * @return list<array<string, mixed>>
     */
    private function manDays(array $workers, CarbonImmutable $from, CarbonImmutable $to, int $rate): array
    {
        if ($rate <= 0) {
            throw new BusinessRuleViolation('Give the rate per man-day.');
        }
        $lines = [];
        foreach ($workers as $e) {
            $period = $this->attendance->period($e, $from, $to);
            $present = (float) $period['totals']['present'];
            if ($present <= 0) {
                continue;
            }
            $days = array_values(array_map(fn (array $d) => $d['date'], array_filter($period['days'], fn (array $d) => ($d['present'] ?? 0) > 0)));
            $qty = number_format($present, 3, '.', '');
            $lines[] = ['employee_id' => $e->id, 'description' => "{$e->code} {$e->name()}", 'quantity' => $qty, 'unit' => 'man-day', 'rate_paise' => $rate,
                'amount_paise' => (int) round($present * $rate), 'source' => ['days' => $days]];
        }

        return $lines;
    }

    /**
     * @param  list<Employee>  $workers
     * @param  array<string, int>  $rates
     * @return list<array<string, mixed>>
     */
    private function piece(array $workers, CarbonImmutable $from, CarbonImmutable $to, array $rates): array
    {
        $ids = array_map(fn (Employee $e) => $e->id, $workers);
        $entries = PieceWorkEntry::query()->whereIn('employee_id', $ids)->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get();
        $lines = [];
        foreach ($entries->groupBy('unit') as $unit => $group) {
            $rate = (int) ($rates[$unit] ?? 0);
            if ($rate <= 0) {
                throw new BusinessRuleViolation("Give the rate per {$unit}.");
            }
            $qty = $group->reduce(fn (string $sum, PieceWorkEntry $p) => bcadd($sum, (string) $p->quantity, 3), '0.000');
            if (bccomp($qty, '0', 3) <= 0) {
                continue;
            }
            $lines[] = ['employee_id' => null, 'description' => 'Piece work: '.$unit.' ('.$group->pluck('employee_id')->unique()->count().' workers)', 'quantity' => $qty,
                'unit' => (string) $unit, 'rate_paise' => $rate, 'amount_paise' => (int) bcdiv(bcadd(bcmul($qty, (string) $rate, 3), '0.5', 3), '1', 0),
                'source' => ['entries' => $group->pluck('id')->all()]];
        }

        return $lines;
    }

    private function require(string $permission): LoginAccount
    {
        $account = $this->actor->account();
        if (! ($account?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }

        return $account;
    }
}
