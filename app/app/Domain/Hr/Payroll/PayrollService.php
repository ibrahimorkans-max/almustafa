<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Documents\Engine\DocumentBatches;
use App\Domain\Hr\Attendance\AttendanceCalculator;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\Advance;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunAttendance;
use App\Models\Hr\Payslip;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The monthly payroll run of an entity:
 *   open → lock attendance (snapshot per employee) → calculate (a new set of items each time) →
 *   approve (a different person with hr.payroll.approve) → finalize (after the month ends: posts the
 *   payroll voucher, records advance recoveries, numbers the payslips; nothing can change after).
 * Before approval the run can be unlocked (with a reason) to take attendance corrections.
 */
final class PayrollService
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly DocumentNumbers $numbers,
        private readonly AttendanceCalculator $attendance,
        private readonly WageEngine $engine,
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly AdvanceService $advances,
    ) {}

    public function open(string $month, ?string $note = null): PayrollRun
    {
        $this->require('hr.payroll.run');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new BusinessRuleViolation('Month must look like 2026-10.');
        }
        if ($month > CarbonImmutable::now('Asia/Karachi')->format('Y-m')) {
            throw new BusinessRuleViolation('A payroll cannot be opened for a month that has not started.');
        }
        if (PayrollRun::query()->where('month', $month)->exists()) {
            throw new BusinessRuleViolation("There is already a payroll for {$month}.");
        }

        return DB::transaction(function () use ($month, $note): PayrollRun {
            $run = new PayrollRun(['month' => $month, 'note' => $note]);
            $run->number = $this->numbers->next(DocumentNumbers::PAYROLL, CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Karachi'));
            $run->save();

            return $run;
        });
    }

    /** Freezes the month's attendance of everyone on the rolls during the month. */
    public function lock(PayrollRun $run): PayrollRun
    {
        $actor = $this->require('hr.payroll.run');

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run = $this->fresh($run, [PayrollRun::DRAFT]);
            [$start, $end] = self::range($run->month);
            $lockNo = $run->lock_no + 1;
            $count = 0;
            foreach ($this->employees($run->month) as $employee) {
                $a = $this->attendance->period($employee, $start, $end);
                PayrollRunAttendance::query()->create([
                    'payroll_run_id' => $run->id, 'lock_no' => $lockNo, 'employee_id' => $employee->id,
                    'days' => $a['days'], 'totals' => $a['totals'], 'created_at' => now(),
                ]);
                $count++;
            }
            if ($count === 0) {
                throw new BusinessRuleViolation('Nobody was on the rolls this month.');
            }
            $run->forceFill(['status' => PayrollRun::LOCKED, 'lock_no' => $lockNo, 'locked_by' => $actor->id, 'locked_at' => now()])->save();
            $this->audit->record('hr.payroll.locked', $run, null, null, ['month' => $run->month, 'employees' => $count, 'lock_no' => $lockNo]);

            return $run;
        });
    }

    /** Calculates everyone from the locked attendance; recalculating keeps the earlier sets as history. */
    public function calculate(PayrollRun $run): PayrollRun
    {
        $actor = $this->require('hr.payroll.run');

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run = $this->fresh($run, [PayrollRun::LOCKED, PayrollRun::CALCULATED]);
            $calc = $run->calculation_no + 1;
            $totals = ['employees' => 0, 'gross_paise' => 0, 'deductions_paise' => 0, 'net_paise' => 0, 'employer_paise' => 0, 'warnings' => 0];
            $snapshots = PayrollRunAttendance::query()->where('payroll_run_id', $run->id)->where('lock_no', $run->lock_no)->get();
            foreach ($snapshots as $snap) {
                /** @var Employee $employee */
                $employee = Employee::query()->with(['person', 'department', 'designation', 'shift'])->findOrFail($snap->employee_id);
                $result = $this->engine->calculate($employee, $run->month, ['days' => $snap->days, 'totals' => $snap->totals]);
                if ($result['pay_basis'] === null && $result['gross_paise'] === 0) {
                    $result['warnings'][] = 'Left out of the payslips: no salary structure.';
                }
                PayrollItem::query()->create([
                    'payroll_run_id' => $run->id, 'calculation_no' => $calc, 'employee_id' => $employee->id,
                    'employee' => self::employeeSnapshot($employee), 'result' => $result,
                    'gross_paise' => $result['gross_paise'], 'deductions_paise' => $result['deductions_paise'],
                    'net_paise' => $result['net_paise'], 'employer_paise' => $result['employer_paise'],
                    'warning_count' => count($result['warnings']), 'created_at' => now(),
                ]);
                $totals['employees']++;
                foreach (['gross_paise', 'deductions_paise', 'net_paise', 'employer_paise'] as $k) {
                    $totals[$k] += $result[$k];
                }
                $totals['warnings'] += count($result['warnings']);
            }
            $run->forceFill([
                'status' => PayrollRun::CALCULATED, 'calculation_no' => $calc, 'calculated_by' => $actor->id, 'calculated_at' => now(), 'totals' => $totals,
            ])->save();
            $this->audit->record('hr.payroll.calculated', $run, null, $totals, ['calculation_no' => $calc]);

            return $run;
        });
    }

    public function approve(PayrollRun $run, ?string $note = null): PayrollRun
    {
        $approver = $this->require('hr.payroll.approve');

        return DB::transaction(function () use ($run, $approver, $note): PayrollRun {
            $run = $this->fresh($run, [PayrollRun::CALCULATED]);
            if ($run->calculated_by === $approver->id || $run->locked_by === $approver->id) {
                throw new BusinessRuleViolation('A different person must approve the payroll you prepared.');
            }
            $run->forceFill(['status' => PayrollRun::APPROVED, 'approved_by' => $approver->id, 'approved_at' => now(), 'note' => $note ?? $run->note])->save();
            $this->audit->record('hr.payroll.approved', $run, null, $run->totals, ['calculation_no' => $run->calculation_no]);

            return $run;
        });
    }

    /** Back to draft before approval (attendance can be corrected, then locked and calculated again). */
    public function unlock(PayrollRun $run, string $reason): PayrollRun
    {
        $this->require('hr.payroll.run');
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for unlocking.');
        }

        return DB::transaction(function () use ($run, $reason): PayrollRun {
            $run = $this->fresh($run, [PayrollRun::LOCKED, PayrollRun::CALCULATED]);
            $run->forceFill(['status' => PayrollRun::DRAFT])->save();
            $this->audit->record('hr.payroll.unlocked', $run, null, null, ['reason' => trim($reason)]);

            return $run;
        });
    }

    /**
     * After the month has ended: posts the payroll voucher on the last day of the month, records the
     * advance recoveries and numbers the payslips. The run can never change afterwards.
     */
    public function finalize(PayrollRun $run): PayrollRun
    {
        $actor = $this->require('hr.payroll.approve');

        $run = DB::transaction(function () use ($run, $actor): PayrollRun {
            $run = $this->fresh($run, [PayrollRun::APPROVED]);
            [, $end] = self::range($run->month);
            if ($end->greaterThanOrEqualTo(CarbonImmutable::now('Asia/Karachi')->startOfDay())) {
                throw new BusinessRuleViolation('The payroll can be finalized only after the month has ended.');
            }
            $items = $run->currentItems()->orderBy('employee->code')->get();
            $voucher = $this->books->post('journal', $end, $this->voucherLines($items),
                "Payroll {$run->month} ({$items->count()} employees)", ['source_type' => 'payroll_run', 'source_id' => $run->id]);

            foreach ($items as $item) {
                foreach ($item->result['deductions'] as $line) {
                    if ($line['code'] === 'ADV' && isset($line['meta']['advance_id']) && $line['amount_paise'] > 0) {
                        $this->advances->recover(Advance::query()->findOrFail($line['meta']['advance_id']), (int) $line['amount_paise'], $end, 'payroll', $item->id, $voucher->id, "Payroll {$run->month}");
                    }
                }
                if ($item->gross_paise > 0) {
                    Payslip::query()->create([
                        'payroll_run_id' => $run->id, 'payroll_item_id' => $item->id, 'employee_id' => $item->employee_id,
                        'number' => $this->numbers->next(DocumentNumbers::PAYSLIP, $end), 'month' => $run->month,
                        'net_paise' => $item->net_paise, 'created_at' => now(),
                    ]);
                }
            }
            $run->forceFill(['status' => PayrollRun::FINALIZED, 'finalized_by' => $actor->id, 'finalized_at' => now(), 'voucher_id' => $voucher->id])->save();
            $this->audit->record('hr.payroll.finalized', $run, null, $run->totals, ['voucher' => $voucher->number]);

            return $run;
        });
        $this->autoIssuePayslips($run);

        return $run;
    }

    /** When the setting is on, every payslip of a just-finalized payroll is issued on the queue. */
    private function autoIssuePayslips(PayrollRun $run): void
    {
        [, $end] = self::range($run->month);
        if (! app(PayrollSettings::class)->get('auto_issue_payslips', $end) || ! $this->actorCan('hr.documents.generate')) {
            return;
        }
        $ids = $run->currentItems()->whereHas('payslip')->orderBy('employee->code')->pluck('id')->all();
        if ($ids !== []) {
            app(DocumentBatches::class)->start('payslip', $ids, "Payslips {$run->month}", ['paper' => 'half_a4']);
        }
    }

    /**
     * Payroll voucher: expenses by head and employer contributions (Dr) against what is owed to staff,
     * the tax authorities, EOBI / PESSI, and the advances recovered (Cr).
     *
     * @param  Collection<int, PayrollItem>  $items
     * @return list<array{account: LedgerAccount, dr?: int, cr?: int, memo?: string}>
     */
    private function voucherLines(Collection $items): array
    {
        $dr = ['salaries_expense' => 0, 'overtime_expense' => 0, 'bonus_expense' => 0, 'eobi_expense' => 0, 'pessi_expense' => 0];
        $cr = ['salaries_payable' => 0, 'salary_tax_payable' => 0, 'eobi_payable' => 0, 'pessi_payable' => 0, 'staff_advances' => 0, 'staff_other_deductions' => 0];
        foreach ($items as $item) {
            foreach ($item->result['earnings'] as $l) {
                $key = match (true) {
                    in_array($l['code'], ['OT', 'HOLIDAY_WORK'], true) => 'overtime_expense',
                    ($l['meta']['type'] ?? null) === 'bonus' => 'bonus_expense',
                    default => 'salaries_expense',
                };
                $dr[$key] += (int) $l['amount_paise'];
            }
            foreach ($item->result['employer'] as $l) {
                [$expense, $payable] = $l['code'] === 'EOBI_ER' ? ['eobi_expense', 'eobi_payable'] : ['pessi_expense', 'pessi_payable'];
                $dr[$expense] += (int) $l['amount_paise'];
                $cr[$payable] += (int) $l['amount_paise'];
            }
            foreach ($item->result['deductions'] as $l) {
                $key = match ($l['code']) {
                    'TAX' => 'salary_tax_payable', 'EOBI' => 'eobi_payable', 'ADV' => 'staff_advances', default => 'staff_other_deductions',
                };
                $cr[$key] += (int) $l['amount_paise'];
            }
            $cr['salaries_payable'] += $item->net_paise;
        }
        $lines = [];
        foreach ($dr as $key => $amount) {
            $lines[] = ['account' => $this->chart->system($key), 'dr' => $amount];
        }
        foreach ($cr as $key => $amount) {
            $lines[] = ['account' => $this->chart->system($key), 'cr' => $amount];
        }

        return $lines;
    }

    /** @return Collection<int, Employee> everyone on the rolls at some point of the month */
    public function employees(string $month): Collection
    {
        [$start, $end] = self::range($month);

        return Employee::query()->with(['person', 'shift'])
            ->where('joining_date', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('exited_on')->orWhere('exited_on', '>=', $start->toDateString()))
            ->orderBy('code')->get();
    }

    /** @return array<string, mixed> what the payslip shows about the employee, as on the day of calculation */
    public static function employeeSnapshot(Employee $e): array
    {
        return [
            'code' => $e->code, 'name' => $e->name(), 'department' => $e->department?->name, 'designation' => $e->designation?->name,
            'employment_type' => $e->employment_type, 'joining_date' => $e->joining_date->toDateString(),
            'cnic_last4' => $e->cnic_last4, 'ntn_last4' => $e->ntn_last4, 'eobi_number' => $e->eobi_number, 'pessi_number' => $e->pessi_number,
            'bank_name' => $e->bank_name, 'bank_code' => $e->bank_code, 'bank_last4' => $e->bank_account_last4,
            'shift' => $e->shift?->name,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day (PKT) */
    public static function range(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Karachi');

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    /** @param  list<string>  $allowed */
    private function fresh(PayrollRun $run, array $allowed): PayrollRun
    {
        /** @var PayrollRun $fresh */
        $fresh = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
        if (! in_array($fresh->status, $allowed, true)) {
            throw new BusinessRuleViolation('The payroll is '.(PayrollRun::STATUSES[$fresh->status] ?? $fresh->status).'; this step is not possible now.');
        }

        return $fresh;
    }

    private function actorCan(string $permission): bool
    {
        return $this->actor->account()?->hasPermission($permission) ?? false;
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
