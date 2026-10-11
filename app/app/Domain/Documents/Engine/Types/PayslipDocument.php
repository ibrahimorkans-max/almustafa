<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

use App\Domain\Hr\Leave\LeaveService;
use App\Domain\Hr\Payroll\PayrollYtd;
use App\Domain\Hr\Payroll\SalaryPaymentService;
use App\Models\Accounts\Voucher;
use App\Models\Hr\Advance;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveType;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunAttendance;
use Illuminate\Database\Eloquent\Model;

/**
 * Payslip (monthly salary) or wage slip (daily wage, piece rate) of one employee and month, from the
 * payroll item exactly as calculated and finalized: every earning and deduction with its basis, the
 * employer contributions, attendance, tax-year-to-date, leave and advance balances, and whether the
 * net pay was paid. A calculated (not finalized) item can only be previewed as a draft.
 */
final class PayslipDocument extends BaseDocument
{
    public function key(): string
    {
        return 'payslip';
    }

    public function label(): string
    {
        return 'Payslip / wage slip';
    }

    public function sourceClass(): string
    {
        return PayrollItem::class;
    }

    public function isFinal(Model $source): bool
    {
        /** @var PayrollItem $source */
        $run = $source->run;

        return $run->status === PayrollRun::FINALIZED && $run->calculation_no === $source->calculation_no && $source->payslip !== null;
    }

    public function number(Model $source): ?string
    {
        /** @var PayrollItem $source */
        return $source->payslip?->number;
    }

    public function series(): string
    {
        return 'PSL';
    }

    public function employeeId(Model $source): string
    {
        /** @var PayrollItem $source */
        return $source->employee_id;
    }

    public function defaultPaper(): string
    {
        return 'half_a4';
    }

    public function build(Model $source, array $options): array
    {
        /** @var PayrollItem $source */
        $run = $source->run;
        $r = $source->result;
        $e = Employee::query()->with(['designation', 'department', 'person'])->findOrFail($source->employee_id);
        $info = $r['info'] ?? [];
        $lines = fn (string $section) => array_map(fn (array $l) => [['h' => $l['code'], 't' => $l['name']], (string) $l['basis'], self::money((int) $l['amount_paise'])], $r[$section] ?? []);
        $att = $r['attendance'] ?? [];

        $doc = [
            'title' => $r['pay_basis'] === 'monthly' ? 'payslip' : 'wage_slip',
            'date' => ($run->finalized_at ?? $run->calculated_at ?? now())->timezone('Asia/Karachi')->toDateString(),
            'period' => $run->month,
            'fields' => [
                ['month', ['ym' => $run->month]],
                ...$this->employeeFields($e, $source->employee, bank: true),
                ['joining_date', ['d' => $source->employee['joining_date'] ?? null]],
                ['pay_basis', ['v' => $r['pay_basis']]],
                ['paid_days', ['n' => (string) ($info['paid_days'] ?? '—')]],
            ],
            'tables' => [
                ['k' => 'earnings', 'cols' => ['item', 'basis', 'amount'], 'detail' => [1], 'rows' => $lines('earnings'),
                    'total' => [['k' => 'gross'], null, self::money($source->gross_paise)]],
                ['k' => 'deductions', 'cols' => ['item', 'basis', 'amount'], 'detail' => [1], 'rows' => $lines('deductions'),
                    'total' => [['k' => 'total_deductions'], null, self::money($source->deductions_paise)]],
            ],
            'summary' => [['gross', $source->gross_paise], ['total_deductions', $source->deductions_paise], ['net_pay', $source->net_paise, true]],
            'words' => $source->net_paise,
            'extras' => [
                'attendance' => ['k' => 'attendance_calendar', 'cols' => ['item', 'quantity'], 'rows' => array_values(array_filter([
                    [['k' => 'calendar_days'], ['n' => (string) ($info['calendar_days'] ?? '—')]],
                    [['k' => 'present'], ['n' => (string) ($att['present'] ?? 0)]],
                    [['k' => 'paid_leave'], ['n' => (string) ($att['paid_leave'] ?? 0)]],
                    ($att['unpaid_leave'] ?? 0) > 0 ? [['k' => 'unpaid_leave'], ['n' => (string) $att['unpaid_leave']]] : null,
                    [['k' => 'absent'], ['n' => (string) ($att['absent'] ?? 0)]],
                    [['k' => 'holidays'], ['n' => (string) (($att['holidays'] ?? 0) + ($att['weekly_offs'] ?? 0))]],
                    ($att['overtime_minutes'] ?? 0) > 0 ? [['k' => 'overtime_hours'], ['n' => number_format(((int) $att['overtime_minutes']) / 60, 1)]] : null,
                    ($att['late_minutes'] ?? 0) > 0 ? [['k' => 'late_minutes'], ['n' => (string) $att['late_minutes']]] : null,
                ]))],
                'employer' => ['k' => 'employer', 'cols' => ['item', 'basis', 'amount'], 'detail' => [1], 'rows' => $lines('employer')],
                'ytd' => $this->ytd($source, $run),
                'leave' => $this->leave($e, $run->month),
                'loan' => $this->loans($e),
                'calendar' => $this->calendar($source, $run),
            ],
            'status' => $this->status($source, $run),
            'signatures' => ['prepared_by', 'approved_by', 'employee_signature'],
        ];

        return $doc;
    }

    /** @return array<string, mixed> */
    private function ytd(PayrollItem $item, PayrollRun $run): array
    {
        $before = app(PayrollYtd::class)->before($item->employee_id, $run->month);

        return ['k' => 'ytd', 'cols' => ['item', 'amount'], 'rows' => [
            [['k' => 'ytd_gross'], self::money($before['gross_paise'] + $item->gross_paise)],
            [['k' => 'ytd_tax'], self::money($before['tax_paise'] + $item->line('deductions', 'TAX'))],
            [['k' => 'ytd_net'], self::money($before['net_paise'] + $item->net_paise)],
        ]];
    }

    /** @return array<string, mixed> */
    private function leave(Employee $e, string $month): array
    {
        $year = (int) substr($month, 0, 4);
        $rows = [];
        foreach (LeaveType::query()->where('status', 'active')->orderBy('code')->get() as $type) {
            $rows[] = [$type->name, ['n' => app(LeaveService::class)->balance($e, $type, $year)]];
        }

        return ['k' => 'leave_balance', 'cols' => ['leave_type', 'balance'], 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function loans(Employee $e): array
    {
        $rows = [];
        foreach (Advance::query()->where('employee_id', $e->id)->whereIn('status', [Advance::DISBURSED])->orderBy('disbursed_at')->get() as $a) {
            $rows[] = [(string) $a->number.' · '.(Advance::KINDS[$a->kind] ?? $a->kind), self::money($a->amount_paise), self::money($a->balancePaise())];
        }

        return ['k' => 'loan_balance', 'cols' => ['item', 'amount', 'balance'], 'rows' => $rows];
    }

    /** @return list<array{0: int, 1: string}> */
    private function calendar(PayrollItem $item, PayrollRun $run): array
    {
        $snap = PayrollRunAttendance::query()->where('payroll_run_id', $run->id)->where('employee_id', $item->employee_id)->orderByDesc('lock_no')->first();

        return array_map(fn (array $d) => [(int) substr((string) $d['date'], 8, 2), (string) $d['code']], $snap->days ?? []);
    }

    /** @return list<array{0: string, 1: mixed}> */
    private function status(PayrollItem $item, PayrollRun $run): array
    {
        if ($run->status !== PayrollRun::FINALIZED) {
            return [];
        }
        $paid = app(SalaryPaymentService::class)->status($item);

        return array_values(array_filter([
            $run->voucher_id !== null ? ['voucher', (string) Voucher::query()->whereKey($run->voucher_id)->value('number')] : null,
            ['status', ['v' => match ($paid) {
                'paid' => 'paid', 'partial' => 'part_paid', 'nothing' => 'posted', default => 'unpaid'
            }]],
        ]));
    }
}
