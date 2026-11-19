<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Hr\Payroll\AdvanceService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\Advance;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\SalaryStructure;
use App\Models\Hr\SalaryVoucher;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Salary vouchers: an employee's salary for a month — or a bonus / other payment — paid on the spot.
 *  - the salary is filled from the employee's salary for that month (monthly; or the daily wage × days worked) and may
 *    be changed;
 *  - an advance / loan instalment is taken off (recorded against the advance) and other deductions (fine, canteen …);
 *  - paid in cash, by cheque, bank or wallet, from the chosen cash / bank account; any amount;
 *  - one salary per employee per month: a month paid here is left out of the payroll run, and a month already in a
 *    finalized payroll run cannot be paid here.
 * Posts: Dr Salaries (or Bonus) / Cr Staff advances (instalment) / Cr Other deductions / Cr cash-bank (net).
 */
final class SalaryVouchers
{
    public const RIGHT = 'hr.payroll.run';

    public function __construct(
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly DocumentNumbers $numbers,
        private readonly ActorContext $actor,
    ) {}

    public static function allowed(): bool
    {
        return app(ActorContext::class)->account()?->hasPermission(self::RIGHT) ?? false;
    }

    /** The employee's salary structure in force on a day. */
    public static function structureOn(Employee $employee, CarbonImmutable $day): ?SalaryStructure
    {
        return SalaryStructure::query()->with('lines.component')->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $day->toDateString())->orderByDesc('effective_from')->orderByDesc('created_at')->first();
    }

    /**
     * What the voucher starts with for a month: the salary (monthly, or the daily wage × days), the next advance
     * instalment, and whether the month is already paid.
     *
     * @return array{basis: string|null, salary_paise: int, daily_paise: int|null, advance_id: string|null, instalment_paise: int, paid: bool}
     */
    public static function suggestion(Employee $employee, string $month, ?string $days = null): array
    {
        $end = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Karachi')->endOfMonth();
        $structure = self::structureOn($employee, $end);
        $earnings = $structure?->totalPaise('earning') ?? 0;
        $daily = $structure?->pay_basis === 'daily' ? $earnings : null;
        $salary = $daily !== null ? (int) round($daily * (float) ($days ?? 0)) : $earnings;
        $advance = Advance::query()->where('employee_id', $employee->id)->where('status', Advance::DISBURSED)->orderBy('disbursed_at')->get()
            ->first(fn (Advance $a) => $a->balancePaise() > 0);

        return [
            'basis' => $structure?->pay_basis, 'salary_paise' => $salary, 'daily_paise' => $daily,
            'advance_id' => $advance?->id, 'instalment_paise' => $advance === null ? 0 : min($advance->installment_paise ?: $advance->balancePaise(), $advance->balancePaise()),
            'paid' => self::monthPaid($employee, $month),
        ];
    }

    /** Whether the month's salary is already paid: by a salary voucher, or in a finalized payroll run. */
    public static function monthPaid(Employee $employee, string $month): bool
    {
        return SalaryVoucher::query()->where('employee_id', $employee->id)->where('kind', 'salary')->where('month', $month)->exists()
            || PayrollItem::query()->where('employee_id', $employee->id)
                ->whereHas('run', fn ($q) => $q->where('month', $month)->where('status', PayrollRun::FINALIZED))->exists();
    }

    /**
     * @param  array<string, mixed>  $data  employee_id, kind, month, days, gross, advance, other_deduction, other_deduction_note,
     *                                      mode, ledger_id, reference, paid_at, note
     */
    public function create(array $data): SalaryVoucher
    {
        if (! self::allowed()) {
            throw new BusinessRuleViolation("You need '".self::RIGHT."' to pay salaries.");
        }
        $employee = Employee::query()->findOrFail($data['employee_id'] ?? null);
        $kind = (string) ($data['kind'] ?? 'salary');
        if (! isset(SalaryVoucher::KINDS[$kind])) {
            throw new BusinessRuleViolation('Choose what is paid.');
        }
        $month = $kind === 'salary' ? (string) ($data['month'] ?? '') : (filled($data['month'] ?? null) ? (string) $data['month'] : null);
        if ($month !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new BusinessRuleViolation('Choose the month.');
        }
        $paidAt = CarbonImmutable::parse((string) ($data['paid_at'] ?? 'now'), 'Asia/Karachi');
        if ($paidAt->isAfter(now()->addHour())) {
            throw new BusinessRuleViolation('The date cannot be in the future.');
        }
        $gross = $this->rupees($data['gross'] ?? null, 'Salary / amount');
        $instalment = $this->rupees($data['advance'] ?? null, 'Advance taken off', true);
        $other = $this->rupees($data['other_deduction'] ?? null, 'Other deduction', true);
        $net = $gross - $instalment - $other;
        if ($net < 0) {
            throw new BusinessRuleViolation('The deductions are more than the salary.');
        }
        $mode = (string) ($data['mode'] ?? 'cash');
        if (! isset(SalaryVoucher::MODES[$mode])) {
            throw new BusinessRuleViolation('Choose how it is paid.');
        }
        $ledger = LedgerAccount::query()->find($data['ledger_id'] ?? null);
        if ($ledger === null || ! in_array($ledger->kind, ['cash', 'bank'], true) || $ledger->status !== 'active') {
            throw new BusinessRuleViolation('Choose the cash or bank account it is paid from.');
        }
        if ($mode === 'cash' && $ledger->kind !== 'cash') {
            throw new BusinessRuleViolation('Cash is paid from a cash account.');
        }
        if ($mode === 'cheque' && blank($data['reference'] ?? null)) {
            throw new BusinessRuleViolation('Type the cheque number.');
        }

        return DB::transaction(function () use ($employee, $kind, $month, $data, $paidAt, $gross, $instalment, $other, $net, $mode, $ledger): SalaryVoucher {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();   // one at a time per employee
            if ($kind === 'salary' && self::monthPaid($employee, (string) $month)) {
                throw new BusinessRuleViolation("{$employee->name()}'s salary for {$month} is already paid.");
            }
            $advance = null;
            if ($instalment > 0) {
                $advance = Advance::query()->lockForUpdate()->find($data['advance_id'] ?? null);
                if ($advance === null || $advance->employee_id !== $employee->id || $advance->status !== Advance::DISBURSED) {
                    throw new BusinessRuleViolation('Choose the employee\'s advance or loan the instalment is taken from.');
                }
                if ($instalment > $advance->balancePaise()) {
                    throw new BusinessRuleViolation(sprintf('Only %s is left on that advance.', Money::paise($advance->balancePaise())->format()));
                }
            }
            $number = $this->numbers->next(DocumentNumbers::SALARY_VOUCHER, $paidAt);
            $what = SalaryVoucher::KINDS[$kind].($month ? ' '.CarbonImmutable::createFromFormat('!Y-m', $month)->format('M Y') : '');
            $voucher = $this->books->post('payment', $paidAt, [
                ['account' => $this->chart->system($kind === 'bonus' ? 'bonus_expense' : 'salaries_expense'), 'dr' => $gross, 'memo' => "{$employee->code} {$employee->name()} · {$what}"],
                ['account' => $this->chart->system('staff_advances'), 'cr' => $instalment, 'memo' => $advance?->number],
                ['account' => $this->chart->system('staff_other_deductions'), 'cr' => $other, 'memo' => $data['other_deduction_note'] ?? null],
                ['account' => $ledger, 'cr' => $net, 'memo' => SalaryVoucher::MODES[$mode].(filled($data['reference'] ?? null) ? ' '.$data['reference'] : '')],
            ], "{$what} paid to {$employee->code} {$employee->name()} ({$number})", ['source_type' => 'salary_voucher']);

            $row = SalaryVoucher::query()->create([
                'number' => $number, 'employee_id' => $employee->id, 'kind' => $kind, 'month' => $month,
                'days' => filled($data['days'] ?? null) ? (string) $data['days'] : null,
                'gross_paise' => $gross, 'advance_paise' => $instalment, 'advance_id' => $advance?->id,
                'other_deduction_paise' => $other, 'other_deduction_note' => filled($data['other_deduction_note'] ?? null) ? mb_substr((string) $data['other_deduction_note'], 0, 120) : null,
                'net_paise' => $net, 'mode' => $mode, 'ledger_id' => $ledger->id,
                'reference' => filled($data['reference'] ?? null) ? mb_substr(trim((string) $data['reference']), 0, 60) : null,
                'paid_at' => $paidAt->utc(), 'voucher_id' => $voucher->id,
                'note' => filled($data['note'] ?? null) ? mb_substr((string) $data['note'], 0, 1000) : null,
                'created_by' => $this->actor->accountId(), 'created_at' => now(),
            ]);
            if ($advance !== null) {
                app(AdvanceService::class)->recover($advance, $instalment, $paidAt, 'sal_voucher', $row->id, $voucher->id, $number);
            }

            return $row;
        });
    }

    private function rupees(mixed $value, string $what, bool $zeroOk = false): int
    {
        if ($zeroOk && blank($value)) {
            return 0;
        }
        try {
            $paise = Money::rupees((string) $value)->paise;
        } catch (InvalidArgumentException) {
            throw new BusinessRuleViolation("{$what}: type the amount in rupees.");
        }
        if ($paise < 0 || (! $zeroOk && $paise === 0)) {
            throw new BusinessRuleViolation("{$what}: type the amount in rupees.");
        }

        return $paise;
    }
}
