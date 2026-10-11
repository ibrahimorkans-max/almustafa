<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

use App\Models\Hr\Employee;
use App\Models\Hr\PayAdjustment;
use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/** Bonus or allowance slip for one approved bonus / other earning (paid with that month's salary). */
final class BonusSlipDocument extends BaseDocument
{
    public function key(): string
    {
        return 'bonus_slip';
    }

    public function label(): string
    {
        return 'Bonus / allowance slip';
    }

    public function sourceClass(): string
    {
        return PayAdjustment::class;
    }

    public function isFinal(Model $source): bool
    {
        /** @var PayAdjustment $source */
        return $source->status === PayAdjustment::APPROVED && in_array($source->type, ['bonus', 'earning'], true);
    }

    public function number(Model $source): ?string
    {
        return null;
    }

    public function series(): string
    {
        return 'BNS';
    }

    public function employeeId(Model $source): string
    {
        /** @var PayAdjustment $source */
        return $source->employee_id;
    }

    public function defaultPaper(): string
    {
        return 'half_a4';
    }

    public function build(Model $source, array $options): array
    {
        /** @var PayAdjustment $source */
        $e = Employee::query()->with(['designation', 'department', 'person'])->findOrFail($source->employee_id);

        return [
            'title' => 'bonus_slip',
            'date' => ($source->decided_at ?? $source->created_at)?->timezone('Asia/Karachi')->toDateString(),
            'period' => $source->month,
            'fields' => [
                ['month', ['ym' => $source->month]],
                ...$this->employeeFields($e),
                ['item', ['h' => $source->code, 't' => $source->name]],
                ['reason', $source->reason],
                ['approved_by', $source->approved_by !== null ? LoginAccount::query()->whereKey($source->approved_by)->value('username') : null],
            ],
            'summary' => [['amount', $source->amount_paise, true]],
            'words' => $source->amount_paise,
            'signatures' => ['hr_manager', 'approved_by', 'received_by'],
        ];
    }
}
