<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

use App\Models\Accounts\Voucher;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollPayment;
use App\Models\Hr\PayrollPaymentAck;
use Illuminate\Database\Eloquent\Model;

/**
 * Receipt for salary paid in cash or by cheque, with the employee's acknowledgement (signature or
 * thumb) noted once it is recorded. Thermal 80 mm by default: it is printed at the pay window.
 */
final class CashReceiptDocument extends BaseDocument
{
    public function key(): string
    {
        return 'cash_receipt';
    }

    public function label(): string
    {
        return 'Salary payment receipt';
    }

    public function sourceClass(): string
    {
        return PayrollPayment::class;
    }

    public function isFinal(Model $source): bool
    {
        return true;   // a payment is posted when it is recorded
    }

    public function number(Model $source): string
    {
        /** @var PayrollPayment $source */
        return $source->number;
    }

    public function series(): string
    {
        return 'SPAY';
    }

    public function employeeId(Model $source): string
    {
        /** @var PayrollPayment $source */
        return $source->employee_id;
    }

    public function defaultPaper(): string
    {
        return 'thermal80';
    }

    public function build(Model $source, array $options): array
    {
        /** @var PayrollPayment $source */
        $e = Employee::query()->with(['designation', 'department', 'person'])->findOrFail($source->employee_id);
        $month = (string) $source->item->run->month;
        $ack = PayrollPaymentAck::query()->where('payroll_payment_id', $source->id)->first();

        return [
            'title' => 'cash_receipt',
            'date' => $source->paid_on->toDateString(),
            'period' => $month,
            'fields' => [
                ['date', ['d' => $source->paid_on->toDateString()]],
                ['month', ['ym' => $month]],
                ...array_slice($this->employeeFields($e, $source->item->employee), 0, 2),
                ['mode', ['v' => $source->mode]],
                ['reference', $source->reference],
            ],
            'paragraphs' => [['receipt', ['amount' => ['m' => $source->amount_paise], 'words' => ['w' => $source->amount_paise], 'month' => ['ym' => $month]]]],
            'summary' => [['amount', $source->amount_paise, true]],
            'words' => $source->amount_paise,
            'status' => array_values(array_filter([
                $source->voucher_id !== null ? ['voucher', (string) Voucher::query()->whereKey($source->voucher_id)->value('number')] : null,
                $ack !== null ? ['acknowledged', ['v' => $ack->method]] : null,
            ])),
            'signatures' => ['accountant', 'received_by'],
        ];
    }
}
