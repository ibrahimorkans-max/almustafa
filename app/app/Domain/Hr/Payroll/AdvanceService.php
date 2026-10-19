<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Approvals\SelfApproval;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\Advance;
use App\Models\Hr\AdvanceRecovery;
use App\Models\Hr\Employee;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Salary advances and staff loans: request (HR) → approve or reject (a different person with
 * hr.payroll.approve, never the employee) → disburse from cash or bank (posts Dr Staff advances /
 * Cr cash-bank) → recovered by payroll instalments or cash repayments → closed at a zero balance.
 */
final class AdvanceService
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly DocumentNumbers $numbers,
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
    ) {}

    public function request(Employee $employee, string $kind, int $amountPaise, int $installmentPaise, string $startMonth, string $reason): Advance
    {
        $this->require('hr.payroll.run');
        if (! array_key_exists($kind, Advance::KINDS)) {
            throw new BusinessRuleViolation('Choose advance or loan.');
        }
        if ($employee->status === Employee::EXITED) {
            throw new BusinessRuleViolation('An exited employee cannot be given an advance.');
        }
        if ($amountPaise <= 0 || $installmentPaise <= 0 || $installmentPaise > $amountPaise) {
            throw new BusinessRuleViolation('The amount must be positive and the monthly instalment between 1 and the amount.');
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $startMonth) || $startMonth < CarbonImmutable::now('Asia/Karachi')->format('Y-m')) {
            throw new BusinessRuleViolation('Recovery must start this month or later (e.g. '.CarbonImmutable::now('Asia/Karachi')->format('Y-m').').');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for the advance.');
        }

        return DB::transaction(function () use ($employee, $kind, $amountPaise, $installmentPaise, $startMonth, $reason): Advance {
            $advance = new Advance([
                'employee_id' => $employee->id, 'kind' => $kind, 'amount_paise' => $amountPaise, 'installment_paise' => $installmentPaise,
                'start_month' => $startMonth, 'reason' => trim($reason), 'requested_by' => $this->actor->accountId(),
            ]);
            $advance->number = $this->numbers->next(DocumentNumbers::ADVANCE);
            $advance->save();

            return $advance;
        });
    }

    public function approve(Advance $advance, ?string $note): Advance
    {
        return $this->decide($advance, Advance::APPROVED, $note);
    }

    public function reject(Advance $advance, string $note): Advance
    {
        if (mb_strlen(trim($note)) < 5) {
            throw new BusinessRuleViolation('Give the reason for refusing.');
        }

        return $this->decide($advance, Advance::REJECTED, $note);
    }

    /** Pays the approved advance out of a cash or bank ledger and posts it. */
    public function disburse(Advance $advance, LedgerAccount $from, CarbonImmutable $date): Advance
    {
        $this->require('hr.payroll.run');
        if (! in_array($from->kind, ['cash', 'bank'], true)) {
            throw new BusinessRuleViolation('Pay the advance from a cash or bank account.');
        }

        return DB::transaction(function () use ($advance, $from, $date): Advance {
            $advance = $this->lock($advance, Advance::APPROVED);
            $employee = Employee::query()->findOrFail($advance->employee_id);
            $voucher = $this->books->post('payment', $date, [
                ['account' => $this->chart->system('staff_advances'), 'dr' => $advance->amount_paise, 'memo' => "{$advance->number} {$employee->code}"],
                ['account' => $from, 'cr' => $advance->amount_paise, 'memo' => "{$advance->number} {$employee->code}"],
            ], Advance::KINDS[$advance->kind]." {$advance->number} to {$employee->code} {$employee->name()}", ['source_type' => 'advance', 'source_id' => $advance->id]);
            $advance->forceFill([
                'status' => Advance::DISBURSED, 'paid_from_ledger_id' => $from->id, 'voucher_id' => $voucher->id, 'disbursed_at' => $date->setTime(12, 0)->utc(),
            ])->save();
            $this->audit->record('hr.advance.disbursed', $advance, null, null, ['voucher' => $voucher->number, 'amount_paise' => $advance->amount_paise]);

            return $advance;
        });
    }

    /** The employee repays (part of) the balance in cash or to the bank; posts Dr cash-bank / Cr Staff advances. */
    public function repay(Advance $advance, int $amountPaise, LedgerAccount $into, CarbonImmutable $date, string $note): AdvanceRecovery
    {
        $this->require('hr.payroll.run');
        if (! in_array($into->kind, ['cash', 'bank'], true)) {
            throw new BusinessRuleViolation('Receive the repayment into a cash or bank account.');
        }

        return DB::transaction(function () use ($advance, $amountPaise, $into, $date, $note): AdvanceRecovery {
            $advance = $this->lock($advance, Advance::DISBURSED);
            if ($amountPaise <= 0 || $amountPaise > $advance->balancePaise()) {
                throw new BusinessRuleViolation('The repayment must be positive and not more than the balance ('.PayContext::money($advance->balancePaise()).').');
            }
            $voucher = $this->books->post('receipt', $date, [
                ['account' => $into, 'dr' => $amountPaise, 'memo' => "Repayment {$advance->number}"],
                ['account' => $this->chart->system('staff_advances'), 'cr' => $amountPaise, 'memo' => "Repayment {$advance->number}"],
            ], "Repayment of {$advance->number}", ['source_type' => 'advance', 'source_id' => $advance->id]);

            return $this->recover($advance, $amountPaise, $date, 'cash', null, $voucher->id, $note);
        });
    }

    /**
     * Records a recovery (payroll calls this when the month is finalized; the payroll voucher
     * carries the accounting) and closes the advance when nothing is left.
     */
    public function recover(Advance $advance, int $amountPaise, CarbonImmutable $date, string $source, ?string $sourceId, ?string $voucherId, ?string $note = null): AdvanceRecovery
    {
        if ($amountPaise <= 0 || $amountPaise > $advance->balancePaise()) {
            throw new BusinessRuleViolation("Recovery for {$advance->number} is more than its balance.");
        }
        $recovery = AdvanceRecovery::query()->create([
            'advance_id' => $advance->id, 'amount_paise' => $amountPaise, 'recovered_on' => $date->toDateString(),
            'source' => $source, 'source_id' => $sourceId, 'voucher_id' => $voucherId, 'note' => $note, 'created_at' => now(),
        ]);
        $remaining = Advance::query()->findOrFail($advance->id)->balancePaise();
        if ($remaining === 0) {
            $advance->forceFill(['status' => Advance::CLOSED])->save();
        }
        $this->audit->record('hr.advance.recovered', $advance, null, null, ['amount_paise' => $amountPaise, 'source' => $source, 'balance_paise' => $remaining]);

        return $recovery;
    }

    /**
     * Planned instalments month by month (from the start month until the balance is covered), next to
     * what was actually recovered — the recovery schedule statement.
     *
     * @return list<array{month: string, planned_paise: int, recovered_paise: int, balance_after_paise: int}>
     */
    public function schedule(Advance $advance): array
    {
        $recovered = [];
        foreach ($advance->recoveries()->orderBy('recovered_on')->get() as $r) {
            $m = $r->recovered_on->format('Y-m');
            $recovered[$m] = ($recovered[$m] ?? 0) + $r->amount_paise;
        }
        $rows = [];
        $balance = $advance->amount_paise;
        $month = CarbonImmutable::createFromFormat('!Y-m', $advance->start_month);
        $months = array_keys($recovered);
        $last = max([$advance->start_month, ...$months]);
        for ($i = 0; $i < 240 && ($balance > 0 || $month->format('Y-m') <= $last); $i++) {
            $m = $month->format('Y-m');
            $got = $recovered[$m] ?? 0;
            $planned = min($advance->installment_paise, $balance);
            $balance -= $got;
            $rows[] = ['month' => $m, 'planned_paise' => $planned, 'recovered_paise' => $got, 'balance_after_paise' => $balance];
            if ($balance <= 0 && $m >= $last) {
                break;
            }
            $month = $month->addMonth();
        }

        return $rows;
    }

    private function decide(Advance $advance, string $status, ?string $note): Advance
    {
        $approver = $this->require('hr.payroll.approve');

        return DB::transaction(function () use ($advance, $status, $note, $approver): Advance {
            $advance = $this->lock($advance, Advance::REQUESTED);
            if ($advance->requested_by === $approver->id && ! SelfApproval::allowed($approver)) {
                throw new BusinessRuleViolation('A different person must decide the advance you requested.');
            }
            if (Employee::query()->whereKey($advance->employee_id)->value('person_id') === $approver->person_id) {
                throw new BusinessRuleViolation('You cannot decide your own advance.');
            }
            $advance->forceFill(['status' => $status, 'approved_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            return $advance;
        });
    }

    private function lock(Advance $advance, string $expected): Advance
    {
        /** @var Advance $fresh */
        $fresh = Advance::query()->lockForUpdate()->findOrFail($advance->id);
        if ($fresh->status !== $expected) {
            throw new BusinessRuleViolation("Advance {$fresh->number} is {$fresh->status}.");
        }

        return $fresh;
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
