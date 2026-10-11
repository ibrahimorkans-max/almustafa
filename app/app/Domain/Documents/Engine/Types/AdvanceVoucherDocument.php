<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

use App\Domain\Hr\Payroll\AdvanceService;
use App\Models\Accounts\Voucher;
use App\Models\Hr\Advance;
use App\Models\Hr\Employee;
use Illuminate\Database\Eloquent\Model;

/** Advance / loan voucher with its recovery schedule (planned against recovered), once paid out. */
final class AdvanceVoucherDocument extends BaseDocument
{
    public function key(): string
    {
        return 'advance_voucher';
    }

    public function label(): string
    {
        return 'Advance / loan voucher and recovery schedule';
    }

    public function sourceClass(): string
    {
        return Advance::class;
    }

    public function isFinal(Model $source): bool
    {
        /** @var Advance $source */
        return in_array($source->status, [Advance::DISBURSED, Advance::CLOSED], true) && $source->number !== null;
    }

    public function number(Model $source): ?string
    {
        /** @var Advance $source */
        return $source->number;
    }

    public function series(): string
    {
        return 'ADV';
    }

    public function employeeId(Model $source): string
    {
        /** @var Advance $source */
        return $source->employee_id;
    }

    public function build(Model $source, array $options): array
    {
        /** @var Advance $source */
        $e = Employee::query()->with(['designation', 'department', 'person'])->findOrFail($source->employee_id);
        $schedule = $this->isFinal($source) ? app(AdvanceService::class)->schedule($source) : [];

        return [
            'title' => 'advance_voucher',
            'date' => ($source->disbursed_at ?? $source->created_at)?->timezone('Asia/Karachi')->toDateString(),
            'fields' => [
                ['date', ['d' => ($source->disbursed_at ?? $source->created_at)?->timezone('Asia/Karachi')->toDateString()]],
                ...$this->employeeFields($e),
                ['kind', ['v' => $source->kind]],
                ['amount', self::money($source->amount_paise)],
                ['instalment', self::money($source->installment_paise)],
                ['first_recovery', ['ym' => $source->start_month]],
                ['paid_from', $source->paidFrom?->name],
                ['reason', $source->reason],
            ],
            'tables' => [[
                'k' => 'recovery_schedule', 'cols' => ['month', 'planned', 'recovered', 'balance_after'],
                'rows' => array_map(fn (array $s) => [['ym' => $s['month']], self::money($s['planned_paise']), self::money($s['recovered_paise']), self::money($s['balance_after_paise'])], $schedule),
            ]],
            'summary' => [['amount', $source->amount_paise], ['balance', $source->balancePaise(), true]],
            'words' => $source->amount_paise,
            'status' => array_values(array_filter([
                $source->voucher_id !== null ? ['voucher', (string) Voucher::query()->whereKey($source->voucher_id)->value('number')] : null,
            ])),
            'signatures' => ['prepared_by', 'approved_by', 'received_by'],
        ];
    }
}
