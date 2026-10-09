<?php

declare(strict_types=1);

namespace App\Domain\Hr\Leave;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\Employee;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveLedgerEntry;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Leave requests, approvals and balances. Balances are the sum of an append-only ledger: approving
 * posts "taken", cancelling approved leave posts a "reversal"; nothing is edited.
 */
final class LeaveService
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    public function balance(Employee $employee, LeaveType $type, int $year): string
    {
        $sum = LeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->where('year', $year)->sum('days');

        return number_format((float) $sum, 1, '.', '');
    }

    /** Working days in the range: holidays and the employee's weekly offs are not counted. */
    public function workingDays(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, string $halfDay = 'none'): string
    {
        $holidays = Holiday::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all();
        $offs = array_map('intval', $employee->shift->weekly_offs ?? [7]);
        $days = 0;
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            if (! in_array($d->toDateString(), $holidays, true) && ! in_array($d->isoWeekday(), $offs, true)) {
                $days++;
            }
        }

        return $halfDay !== 'none' && $days > 0 ? '0.5' : number_format($days, 1, '.', '');
    }

    public function request(Employee $employee, LeaveType $type, CarbonImmutable $from, CarbonImmutable $to, string $halfDay, string $reason): LeaveRequest
    {
        $actor = $this->actor->account() ?? throw new BusinessRuleViolation('Not signed in.');
        $own = $actor->person_id === $employee->person_id;
        if (! ($own && $actor->hasPermission('hr.leave.request')) && ! $actor->hasPermission('hr.employee.edit')) {
            throw new BusinessRuleViolation('You can request leave for yourself, or for others with hr.employee.edit.');
        }
        if ($employee->status === Employee::EXITED) {
            throw new BusinessRuleViolation('The employee has exited.');
        }
        if ($type->status !== 'active') {
            throw new BusinessRuleViolation('This leave type is not in use.');
        }
        if ($to->lessThan($from)) {
            throw new BusinessRuleViolation('The leave ends before it starts.');
        }
        if ($from->year !== $to->year) {
            throw new BusinessRuleViolation('Split the leave at 31 December: balances are kept per calendar year.');
        }
        if (! in_array($halfDay, ['none', 'first', 'second'], true)) {
            throw new BusinessRuleViolation('Half day is none, first or second half.');
        }
        if ($halfDay !== 'none' && (! $from->isSameDay($to) || ! $type->half_day_allowed)) {
            throw new BusinessRuleViolation($type->half_day_allowed ? 'A half day is a single day.' : "{$type->name} cannot be taken as half days.");
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessRuleViolation('Give a reason.');
        }
        $days = $this->workingDays($employee, $from, $to, $halfDay);
        if ((float) $days <= 0) {
            throw new BusinessRuleViolation('These dates are all holidays or weekly offs: no leave is needed.');
        }
        $overlap = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
            ->where('from_date', '<=', $to->toDateString())->where('to_date', '>=', $from->toDateString())->exists();
        if ($overlap) {
            throw new BusinessRuleViolation('There is already a pending or approved leave on these dates.');
        }

        return LeaveRequest::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
            'half_day' => $halfDay, 'days' => $days, 'reason' => trim($reason), 'status' => LeaveRequest::PENDING, 'requested_by' => $actor->id,
        ]);
    }

    public function approve(LeaveRequest $request, ?string $note): LeaveRequest
    {
        $approver = $this->approver($request);

        return DB::transaction(function () use ($request, $note, $approver): LeaveRequest {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== LeaveRequest::PENDING) {
                throw new BusinessRuleViolation("This request is already {$request->status}.");
            }
            $type = $request->leaveType;
            $year = (int) $request->from_date->year;
            if ($type->paid) {
                $left = $this->balance($request->employee, $type, $year);
                if (bccomp($left, (string) $request->days, 1) < 0) {
                    throw new BusinessRuleViolation("Only {$left} day(s) of {$type->name} left for {$year}; this request needs {$request->days}. Approve part of it, or ask for leave without pay.");
                }
                $this->post($request->employee, $type, $year, 'taken', '-'.$request->days, $request->id, 'Leave approved');
            }
            $request->forceFill(['status' => LeaveRequest::APPROVED, 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            return $request;
        });
    }

    public function reject(LeaveRequest $request, string $note): LeaveRequest
    {
        $approver = $this->approver($request);
        if (mb_strlen(trim($note)) < 3) {
            throw new BusinessRuleViolation('Give the reason for refusing the leave.');
        }
        if ($request->status !== LeaveRequest::PENDING) {
            throw new BusinessRuleViolation("This request is already {$request->status}.");
        }
        $request->forceFill(['status' => LeaveRequest::REJECTED, 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note])->save();

        return $request;
    }

    /** Pending: the requester or HR. Approved: someone with hr.leave.approve (not the employee); the days go back. */
    public function cancel(LeaveRequest $request, string $reason): LeaveRequest
    {
        $actor = $this->actor->account() ?? throw new BusinessRuleViolation('Not signed in.');
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessRuleViolation('Give the reason for cancelling.');
        }
        if ($request->status === LeaveRequest::PENDING) {
            if ($actor->id !== $request->requested_by && $actor->person_id !== $request->employee->person_id && ! $actor->hasPermission('hr.employee.edit')) {
                throw new BusinessRuleViolation('Only the requester or HR can withdraw a pending request.');
            }
        } elseif ($request->status === LeaveRequest::APPROVED) {
            $this->approver($request);
        } else {
            throw new BusinessRuleViolation("A {$request->status} request cannot be cancelled.");
        }

        return DB::transaction(function () use ($request, $reason): LeaveRequest {
            if ($request->status === LeaveRequest::APPROVED && $request->leaveType->paid) {
                $this->post($request->employee, $request->leaveType, (int) $request->from_date->year, 'reversal', (string) $request->days, $request->id, 'Approved leave cancelled: '.$reason);
            }
            $request->forceFill(['status' => LeaveRequest::CANCELLED, 'decision_note' => trim(($request->decision_note ? $request->decision_note.' | ' : '').'Cancelled: '.$reason)])->save();

            return $request;
        });
    }

    /** Opening balance, accrual or correction by HR, with a note; recorded as a ledger entry. */
    public function adjust(Employee $employee, LeaveType $type, int $year, string $kind, string $days, string $note): LeaveLedgerEntry
    {
        if (! ($this->actor->account()?->hasPermission('hr.employee.edit') ?? false)) {
            throw new BusinessRuleViolation("Adjusting leave balances needs 'hr.employee.edit'.");
        }
        if (! in_array($kind, ['opening', 'accrual', 'adjustment'], true)) {
            throw new BusinessRuleViolation('Use opening, accrual or adjustment.');
        }
        if (! preg_match('/^-?\d{1,3}(\.[05])?$/', $days) || (float) $days === 0.0) {
            throw new BusinessRuleViolation('Days in whole or half days, e.g. 7 or 1.5 (negative for a correction).');
        }
        if ($kind !== 'adjustment' && (float) $days < 0) {
            throw new BusinessRuleViolation('Opening and accrual cannot be negative; use an adjustment.');
        }
        if (mb_strlen(trim($note)) < 3) {
            throw new BusinessRuleViolation('Note why the balance changes.');
        }

        return $this->post($employee, $type, $year, $kind, $days, null, trim($note));
    }

    /**
     * Year end: carry the balance forward up to each type's limit and lapse the rest. Once per
     * employee, type and year.
     *
     * @return int entries written
     */
    public function yearEnd(int $year): int
    {
        $written = 0;
        $types = LeaveType::query()->where('paid', true)->get();
        foreach (Employee::query()->where('status', '!=', Employee::EXITED)->get() as $employee) {
            foreach ($types as $type) {
                $done = LeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
                    // Only outgoing entries mark the year closed; the carry into this year is positive.
                    ->where('year', $year)->whereIn('kind', ['lapse', 'carry_forward'])->where('days', '<', 0)->exists();
                $balance = $this->balance($employee, $type, $year);
                if ($done || (float) $balance <= 0) {
                    continue;
                }
                $carry = $type->carry_forward ? min((float) $balance, (float) $type->max_carry_days) : 0.0;
                $lapse = (float) $balance - $carry;
                DB::transaction(function () use ($employee, $type, $year, $carry, $lapse, &$written): void {
                    if ($lapse > 0) {
                        $this->post($employee, $type, $year, 'lapse', number_format(-$lapse, 1, '.', ''), null, "Year end {$year}: lapsed");
                        $written++;
                    }
                    if ($carry > 0) {
                        // Out of the old year, into the new one.
                        $this->post($employee, $type, $year, 'carry_forward', number_format(-$carry, 1, '.', ''), null, 'Carried to '.($year + 1));
                        $this->post($employee, $type, $year + 1, 'carry_forward', number_format($carry, 1, '.', ''), null, "Carried from {$year}");
                        $written += 2;
                    }
                });
            }
        }

        return $written;
    }

    private function approver(LeaveRequest $request): LoginAccount
    {
        $approver = $this->actor->account();
        if (! ($approver?->hasPermission('hr.leave.approve') ?? false)) {
            throw new BusinessRuleViolation("Deciding leave needs 'hr.leave.approve'.");
        }
        if ($approver->person_id === $request->employee->person_id) {
            throw new BusinessRuleViolation('You cannot decide your own leave.');
        }

        return $approver;
    }

    private function post(Employee $employee, LeaveType $type, int $year, string $kind, string $days, ?string $requestId, ?string $note): LeaveLedgerEntry
    {
        $entry = LeaveLedgerEntry::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year, 'kind' => $kind,
            'days' => $days, 'leave_request_id' => $requestId, 'note' => $note, 'created_at' => now(),
        ]);
        $this->audit->record('hr.leave.ledger', $employee, null, ['type' => $type->code, 'year' => $year, 'kind' => $kind, 'days' => $days],
            ['leave_request_id' => $requestId, 'note' => $note]);

        return $entry;
    }
}
