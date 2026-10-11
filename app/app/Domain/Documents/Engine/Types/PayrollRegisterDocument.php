<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

use App\Models\Accounts\Voucher;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use Illuminate\Database\Eloquent\Model;

/** The payroll register: every employee of the month on one sheet, with the run's totals. A4 landscape. */
final class PayrollRegisterDocument extends BaseDocument
{
    public function key(): string
    {
        return 'payroll_register';
    }

    public function label(): string
    {
        return 'Payroll register';
    }

    public function sourceClass(): string
    {
        return PayrollRun::class;
    }

    public function isFinal(Model $source): bool
    {
        /** @var PayrollRun $source */
        return $source->status === PayrollRun::FINALIZED;
    }

    public function number(Model $source): ?string
    {
        /** @var PayrollRun $source */
        return $source->number;
    }

    public function series(): string
    {
        return 'PR';
    }

    public function employeeId(Model $source): ?string
    {
        return null;
    }

    public function defaultPaper(): string
    {
        return 'a4_landscape';
    }

    public function build(Model $source, array $options): array
    {
        /** @var PayrollRun $source */
        $items = $source->currentItems()->with('payslip')->orderBy('employee->code')->get();
        $rows = [];
        $sum = ['gross' => 0, 'ded' => 0, 'net' => 0, 'er' => 0];
        foreach ($items as $i => $item) {
            /** @var PayrollItem $item */
            $rows[] = [$i + 1, (string) $item->employee['code'], (string) $item->employee['name'], (string) ($item->employee['designation'] ?? ''),
                ['n' => (string) ($item->result['info']['paid_days'] ?? '')], self::money($item->gross_paise), self::money($item->deductions_paise),
                self::money($item->net_paise), self::money($item->employer_paise), (string) ($item->payslip->number ?? '')];
            $sum['gross'] += $item->gross_paise;
            $sum['ded'] += $item->deductions_paise;
            $sum['net'] += $item->net_paise;
            $sum['er'] += $item->employer_paise;
        }

        return [
            'title' => 'payroll_register',
            'date' => ($source->finalized_at ?? $source->calculated_at ?? now())->timezone('Asia/Karachi')->toDateString(),
            'period' => $source->month,
            'fields' => [['month', ['ym' => $source->month]], ['employees', ['n' => (string) count($rows)]]],
            'tables' => [[
                'k' => 'employees', 'cols' => ['sr', 'code', 'name', 'designation', 'paid_days', 'gross', 'total_deductions', 'net_pay', 'employer_cost', 'payslip'],
                'rows' => $rows,
                'total' => [null, null, ['k' => 'total'], null, null, self::money($sum['gross']), self::money($sum['ded']), self::money($sum['net']), self::money($sum['er']), null],
            ]],
            'summary' => [['gross', $sum['gross']], ['total_deductions', $sum['ded']], ['net_pay', $sum['net'], true]],
            'status' => array_values(array_filter([
                $source->voucher_id !== null ? ['voucher', (string) Voucher::query()->whereKey($source->voucher_id)->value('number')] : null,
            ])),
            'signatures' => ['prepared_by', 'accountant', 'approved_by'],
        ];
    }
}
