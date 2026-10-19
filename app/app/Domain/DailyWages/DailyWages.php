<?php

declare(strict_types=1);

namespace App\Domain\DailyWages;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\DailyWages\DailyWageCategory;
use App\Models\DailyWages\DailyWageGroup;
use App\Models\DailyWages\DailyWageSheet;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily wages for unregistered casual labour.
 *
 *  - record(): a wage sheet for one day of work of a group (or one worker): workers, quantity and rate
 *    (per person per day / hour, or per bag, maund, ton, trip, piece), plus extra and minus deductions;
 *  - post(): books it — Dr Daily wages (direct expense); Cr the cash or bank account when paid now,
 *    or Cr Daily wages payable when it is paid later;
 *  - pay(): pays a booked sheet — Dr Daily wages payable, Cr cash or bank;
 *  - cancel(): only a draft.
 *
 * Recording needs `wages.daily.manage`; posting and paying need `wages.daily.pay`.
 */
final class DailyWages
{
    public const SERIES = 'DW';

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly DocumentNumbers $numbers,
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * Gross for a sheet: per-person units (day, hour) are paid to each worker, other units for the whole work.
     */
    public static function gross(DailyWageCategory|string $unit, int $workers, string $quantity, int $ratePaise): int
    {
        $perPerson = $unit instanceof DailyWageCategory ? $unit->perPerson() : (bool) (DailyWageCategory::UNITS[$unit][2] ?? true);
        $units = (float) $quantity * ($perPerson ? $workers : 1);

        return (int) round($units * $ratePaise);
    }

    /**
     * @param  array{work_date: string, category_id: string, workers: int|string, quantity?: string|int|float|null, rate_paise?: int|null,
     *     group_id?: string|null, worker_name?: string|null, extra_paise?: int|null, deduction_paise?: int|null, worker_names?: string|null, description?: string|null}  $data
     */
    public function record(array $data): DailyWageSheet
    {
        $this->require('wages.daily.manage');
        $category = DailyWageCategory::query()->find($data['category_id'] ?? null)
            ?? throw new BusinessRuleViolation('Choose the kind of work.');
        $group = filled($data['group_id'] ?? null) ? DailyWageGroup::query()->find($data['group_id']) : null;
        $worker = trim((string) ($data['worker_name'] ?? ''));
        if ($group === null && $worker === '') {
            throw new BusinessRuleViolation('Choose the labour group, or type the worker\'s name.');
        }
        $workers = (int) $data['workers'];
        if ($workers < 1 || $workers > 500) {
            throw new BusinessRuleViolation('The number of workers must be between 1 and 500.');
        }
        $quantity = (string) ($data['quantity'] ?? '1');
        if (! is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity > 100000) {
            throw new BusinessRuleViolation('The quantity must be more than zero.');
        }
        $rate = (int) ($data['rate_paise'] ?? $category->rate_paise);
        $extra = max(0, (int) ($data['extra_paise'] ?? 0));
        $deduction = max(0, (int) ($data['deduction_paise'] ?? 0));
        $date = CarbonImmutable::parse((string) $data['work_date'], 'Asia/Karachi')->startOfDay();
        if ($rate <= 0) {
            throw new BusinessRuleViolation('The rate must be more than zero.');
        }
        if ($date->greaterThan(CarbonImmutable::now('Asia/Karachi')->endOfDay())) {
            throw new BusinessRuleViolation('Work cannot be entered for a future date.');
        }
        $gross = self::gross($category, $workers, $quantity, $rate);
        $net = $gross + $extra - $deduction;
        if ($net <= 0) {
            throw new BusinessRuleViolation('The deduction cannot be more than the wages.');
        }

        return DB::transaction(function () use ($data, $category, $group, $worker, $workers, $quantity, $rate, $gross, $extra, $deduction, $net, $date): DailyWageSheet {
            $sheet = new DailyWageSheet([
                'work_date' => $date->toDateString(), 'group_id' => $group?->id, 'worker_name' => $group === null ? $worker : null,
                'category_id' => $category->id, 'unit' => $category->unit, 'workers' => $workers, 'quantity' => $quantity, 'rate_paise' => $rate,
                'gross_paise' => $gross, 'extra_paise' => $extra, 'deduction_paise' => $deduction, 'net_paise' => $net,
                'worker_names' => filled($data['worker_names'] ?? null) ? trim((string) $data['worker_names']) : null,
                'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            ]);
            $sheet->number = $this->numbers->next(self::SERIES, $date);
            $sheet->save();
            $this->audit->record('daily_wages.recorded', $sheet, null, null, ['net_paise' => $net, 'workers' => $workers]);

            return $sheet;
        });
    }

    /** Books the sheet; $paidFrom = cash or bank account when paid now, null when it is paid later. */
    public function post(DailyWageSheet $sheet, ?LedgerAccount $paidFrom): DailyWageSheet
    {
        $who = $this->require('wages.daily.pay');
        $this->assertPaysFrom($paidFrom);

        return DB::transaction(function () use ($sheet, $paidFrom, $who): DailyWageSheet {
            $s = DailyWageSheet::query()->with(['group', 'category'])->lockForUpdate()->findOrFail($sheet->id);
            if ($s->status !== DailyWageSheet::DRAFT) {
                throw new BusinessRuleViolation("{$s->number} is not a draft.");
            }
            $date = CarbonImmutable::parse($s->work_date->toDateString(), 'Asia/Karachi');
            $memo = "{$s->number}: {$s->category->name} — {$s->who()}";
            $credit = $paidFrom ?? $this->chart->system('daily_wages_payable');
            $voucher = $this->books->post($paidFrom !== null ? 'payment' : 'journal', $date, [
                ['account' => $this->chart->system('daily_wages'), 'dr' => $s->net_paise, 'memo' => $memo],
                ['account' => $credit, 'cr' => $s->net_paise, 'memo' => $memo],
            ], "Daily wages {$s->number}: {$s->who()}", ['source_type' => 'daily_wage_sheet', 'source_id' => $s->id]);

            $s->forceFill(['voucher_id' => $voucher->id, 'posted_by' => $who->id, 'posted_at' => now(),
                'status' => $paidFrom !== null ? DailyWageSheet::PAID : DailyWageSheet::POSTED] + ($paidFrom !== null
                ? ['paid_from_ledger_id' => $paidFrom->id, 'paid_by' => $who->id, 'paid_at' => now()] : []))->save();
            $this->audit->record('daily_wages.posted', $s, null, null, ['voucher' => $voucher->number, 'paid' => $paidFrom !== null, 'net_paise' => $s->net_paise]);

            return $s;
        });
    }

    /** Pays a booked sheet (owed to the group) from cash or a bank account. */
    public function pay(DailyWageSheet $sheet, LedgerAccount $paidFrom, ?CarbonImmutable $date = null): DailyWageSheet
    {
        $who = $this->require('wages.daily.pay');
        $this->assertPaysFrom($paidFrom);

        return DB::transaction(function () use ($sheet, $paidFrom, $who, $date): DailyWageSheet {
            $s = DailyWageSheet::query()->with(['group', 'category'])->lockForUpdate()->findOrFail($sheet->id);
            if ($s->status !== DailyWageSheet::POSTED) {
                throw new BusinessRuleViolation("{$s->number} is not waiting to be paid.");
            }
            $memo = "{$s->number}: paid to {$s->who()}";
            $voucher = $this->books->post('payment', $date ?? CarbonImmutable::now('Asia/Karachi')->startOfDay(), [
                ['account' => $this->chart->system('daily_wages_payable'), 'dr' => $s->net_paise, 'memo' => $memo],
                ['account' => $paidFrom, 'cr' => $s->net_paise, 'memo' => $memo],
            ], "Daily wages paid {$s->number}: {$s->who()}", ['source_type' => 'daily_wage_sheet', 'source_id' => $s->id]);
            $s->forceFill(['status' => DailyWageSheet::PAID, 'payment_voucher_id' => $voucher->id, 'paid_from_ledger_id' => $paidFrom->id,
                'paid_by' => $who->id, 'paid_at' => now()])->save();
            $this->audit->record('daily_wages.paid', $s, null, null, ['voucher' => $voucher->number, 'net_paise' => $s->net_paise]);

            return $s;
        });
    }

    public function cancel(DailyWageSheet $sheet, string $reason): DailyWageSheet
    {
        $this->require('wages.daily.manage');
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessRuleViolation('Say why it is cancelled.');
        }

        return DB::transaction(function () use ($sheet, $reason): DailyWageSheet {
            $s = DailyWageSheet::query()->lockForUpdate()->findOrFail($sheet->id);
            if ($s->status !== DailyWageSheet::DRAFT) {
                throw new BusinessRuleViolation('Only a draft can be cancelled; a booked sheet is corrected with a reversal voucher.');
            }
            $s->forceFill(['status' => DailyWageSheet::CANCELLED, 'cancel_reason' => trim($reason)])->save();
            $this->audit->record('daily_wages.cancelled', $s, null, null, ['reason' => trim($reason)]);

            return $s;
        });
    }

    /** What is owed to the groups (booked, not paid). */
    public function owed(): int
    {
        return (int) DailyWageSheet::query()->where('status', DailyWageSheet::POSTED)->sum('net_paise');
    }

    /** A line for messages: "12 workers × 1 day × Rs 1,500.00 = Rs 18,000.00". */
    public static function explain(DailyWageSheet $s): string
    {
        $qty = rtrim(rtrim((string) $s->quantity, '0'), '.');
        $perPerson = (bool) (DailyWageCategory::UNITS[$s->unit][2] ?? true);

        return ($perPerson ? "{$s->workers} worker(s) × {$qty} {$s->unit}(s)" : "{$qty} {$s->unit}(s) by {$s->workers} worker(s)")
            .' × '.Money::paise($s->rate_paise)->format().' = '.Money::paise($s->gross_paise)->format()
            .($s->extra_paise ? ' + '.Money::paise($s->extra_paise)->format() : '')
            .($s->deduction_paise ? ' − '.Money::paise($s->deduction_paise)->format() : '')
            .' → '.Money::paise($s->net_paise)->format();
    }

    private function assertPaysFrom(?LedgerAccount $ledger): void
    {
        if ($ledger !== null && ! in_array($ledger->kind, ['cash', 'bank'], true)) {
            throw new BusinessRuleViolation('Pay from the cash or a bank account.');
        }
    }

    private function require(string $permission): LoginAccount
    {
        $account = $this->actor->account();
        if (! ($account?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("This needs the permission {$permission}.");
        }

        return $account;
    }
}
