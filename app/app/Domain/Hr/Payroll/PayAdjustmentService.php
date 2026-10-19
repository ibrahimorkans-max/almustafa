<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Domain\Approvals\SelfApproval;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\Employee;
use App\Models\Hr\PayAdjustment;
use App\Models\Hr\PayrollRun;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bonuses, other earnings, fines and other deductions for one employee and month. Requested by
 * payroll with a reason; counted only after a different person with hr.payroll.approve agrees.
 * Festival bonuses are generated for everyone eligible in one batch and approved together.
 */
final class PayAdjustmentService
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    public function request(Employee $employee, string $month, string $type, string $code, string $name, int $amountPaise, string $reason, ?string $batchId = null): PayAdjustment
    {
        $this->require('hr.payroll.run');
        if (! array_key_exists($type, PayAdjustment::TYPES)) {
            throw new BusinessRuleViolation('Unknown kind of adjustment.');
        }
        if (! preg_match('/^[A-Z][A-Z0-9_]{1,19}$/', $code)) {
            throw new BusinessRuleViolation('The code is 2–20 capital letters, digits or _ (e.g. FESTIVAL, FINE).');
        }
        if ($amountPaise <= 0) {
            throw new BusinessRuleViolation('The amount must be positive.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation($type === 'fine' ? 'A fine needs the act it is for (Payment of Wages Act: after showing cause).' : 'Give the reason.');
        }
        $this->assertMonthOpen($month);

        return PayAdjustment::query()->create([
            'employee_id' => $employee->id, 'month' => $month, 'type' => $type, 'code' => $code, 'name' => trim($name),
            'amount_paise' => $amountPaise, 'reason' => trim($reason), 'batch_id' => $batchId, 'requested_by' => $this->actor->accountId(),
        ]);
    }

    public function approve(PayAdjustment $adjustment, ?string $note = null): PayAdjustment
    {
        return $this->decide($adjustment, PayAdjustment::APPROVED, $note);
    }

    public function reject(PayAdjustment $adjustment, string $note): PayAdjustment
    {
        if (mb_strlen(trim($note)) < 5) {
            throw new BusinessRuleViolation('Give the reason for refusing.');
        }

        return $this->decide($adjustment, PayAdjustment::REJECTED, $note);
    }

    /**
     * Festival bonus for everyone on the rolls: a share of the monthly Basic (10000 bp = one month's
     * Basic), prorated by months of service in the last year when asked, for staff with at least
     * $minServiceMonths. Creates requests in one batch for one approval.
     *
     * @return array{batch_id: string, count: int, total_paise: int, skipped: list<string>}
     */
    public function festivalBonus(string $month, string $name, int $shareBp, int $minServiceMonths, bool $prorateByService, string $reason): array
    {
        $this->require('hr.payroll.run');
        if ($shareBp <= 0 || $shareBp > 30000) {
            throw new BusinessRuleViolation('The bonus share must be between 0 and 3 months of Basic.');
        }
        $this->assertMonthOpen($month);
        $monthEnd = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Karachi')->endOfMonth();
        $batch = (string) Str::uuid7();
        $count = 0;
        $total = 0;
        $skipped = [];

        DB::transaction(function () use ($month, $name, $shareBp, $minServiceMonths, $prorateByService, $reason, $monthEnd, $batch, &$count, &$total, &$skipped): void {
            $employees = Employee::query()->with('person')->where('status', '!=', Employee::EXITED)
                ->where('joining_date', '<=', $monthEnd->toDateString())->orderBy('code')->get();
            foreach ($employees as $e) {
                $service = (int) CarbonImmutable::parse($e->joining_date)->diffInMonths($monthEnd->addDay());
                $structure = $e->salaryOn($monthEnd);
                $basic = (int) ($structure?->lines()->whereHas('component', fn ($q) => $q->where('code', 'BASIC'))->value('amount_paise') ?? 0);
                if ($structure?->pay_basis === 'daily') {
                    $basic *= 26;
                }
                if ($service < $minServiceMonths || $basic <= 0 || $structure?->pay_basis === 'piece_rate') {
                    $skipped[] = $e->code.($service < $minServiceMonths ? ' (service '.$service.' months)' : ' (no monthly Basic)');

                    continue;
                }
                $factor = $prorateByService ? min(12, $service) : 12;
                $amount = intdiv($basic * $shareBp * $factor * 2 + 120000, 240000);
                $this->request($e, $month, 'bonus', 'FESTIVAL', $name, $amount, $reason.($prorateByService && $service < 12 ? " ({$service}/12 for service)" : ''), $batch);
                $count++;
                $total += $amount;
            }
        });
        $this->audit->record('hr.bonus.batch_generated', null, null, null, ['batch_id' => $batch, 'month' => $month, 'count' => $count, 'total_paise' => $total]);

        return ['batch_id' => $batch, 'count' => $count, 'total_paise' => $total, 'skipped' => $skipped];
    }

    /** Approves every pending request of a generated batch at once (same rules as one by one). */
    public function approveBatch(string $batchId, ?string $note = null): int
    {
        $n = 0;
        foreach (PayAdjustment::query()->where('batch_id', $batchId)->where('status', PayAdjustment::REQUESTED)->get() as $a) {
            $this->approve($a, $note);
            $n++;
        }

        return $n;
    }

    private function decide(PayAdjustment $adjustment, string $status, ?string $note): PayAdjustment
    {
        $approver = $this->require('hr.payroll.approve');

        return DB::transaction(function () use ($adjustment, $status, $note, $approver): PayAdjustment {
            /** @var PayAdjustment $a */
            $a = PayAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            if ($a->status !== PayAdjustment::REQUESTED) {
                throw new BusinessRuleViolation("This {$a->name} has already been {$a->status}.");
            }
            if ($a->requested_by === $approver->id && ! SelfApproval::allowed($approver)) {
                throw new BusinessRuleViolation('A different person must decide what you requested.');
            }
            if (Employee::query()->whereKey($a->employee_id)->value('person_id') === $approver->person_id) {
                throw new BusinessRuleViolation('You cannot decide an amount for yourself.');
            }
            $this->assertMonthOpen($a->month);
            $a->forceFill(['status' => $status, 'approved_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            return $a;
        });
    }

    private function assertMonthOpen(string $month): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new BusinessRuleViolation('Month must look like 2026-10.');
        }
        $run = PayrollRun::query()->where('month', $month)->first();
        if ($run !== null && in_array($run->status, [PayrollRun::APPROVED, PayrollRun::FINALIZED], true)) {
            throw new BusinessRuleViolation("The {$month} payroll is already {$run->status}; put it in a later month.");
        }
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
