<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\ExpenseCategories;
use App\Domain\Approvals\SelfApproval;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Files\FileStore;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\Employee;
use App\Models\Hr\Reimbursement;
use App\Models\Hr\ReimbursementLine;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff expense claims. The employee (or HR for them) submits lines with receipt photos; a
 * supervisor checks them; accounts approve, which posts each line to its category's ledger against
 * "Staff expense claims payable"; payment posts the payable against cash or bank. Each step is a
 * different person from the claimant and from the step before. A refused claim is not edited: the
 * employee submits a corrected one.
 */
final class Reimbursements
{
    /** Receipts are required above this amount per line. */
    public const RECEIPT_REQUIRED_ABOVE_PAISE = 500_00;

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly DocumentNumbers $numbers,
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly FileStore $files,
        private readonly ExpenseCategories $categories,
    ) {}

    /**
     * @param  list<array{category_id: string, spent_on: string, description: string, amount_paise: int, receipt?: string|null}>  $lines  receipt = image or PDF bytes
     */
    public function submit(Employee $employee, string $purpose, array $lines): Reimbursement
    {
        $account = $this->actor->account() ?? throw new BusinessRuleViolation('Sign in first.');
        $own = $employee->person_id === $account->person_id;
        if (! $own && ! $account->hasPermission('hr.payroll.run')) {
            throw new BusinessRuleViolation('You can claim only for yourself.');
        }
        if ($employee->status === Employee::EXITED) {
            throw new BusinessRuleViolation('This employee has left.');
        }
        if (mb_strlen(trim($purpose)) < 5) {
            throw new BusinessRuleViolation('Say what the expenses were for.');
        }
        if ($lines === [] || count($lines) > 30) {
            throw new BusinessRuleViolation('A claim has 1 to 30 lines.');
        }
        $this->categories->installDefaults();
        $today = CarbonImmutable::now('Asia/Karachi');
        $checked = [];
        foreach ($lines as $i => $l) {
            $n = $i + 1;
            $category = ExpenseCategory::query()->whereKey($l['category_id'])->where('status', 'active')->first();
            if ($category === null || ! $category->claimable) {
                throw new BusinessRuleViolation("Line {$n}: this category cannot be claimed.");
            }
            $spent = CarbonImmutable::parse($l['spent_on'], 'Asia/Karachi');
            if ($spent->greaterThan($today) || $spent->lessThan($today->subDays(90))) {
                throw new BusinessRuleViolation("Line {$n}: the date must be within the last 90 days.");
            }
            if ((int) $l['amount_paise'] <= 0) {
                throw new BusinessRuleViolation("Line {$n}: the amount must be positive.");
            }
            if (mb_strlen(trim((string) $l['description'])) < 3) {
                throw new BusinessRuleViolation("Line {$n}: describe the expense.");
            }
            if ((int) $l['amount_paise'] > self::RECEIPT_REQUIRED_ABOVE_PAISE && blank($l['receipt'] ?? null)) {
                throw new BusinessRuleViolation("Line {$n}: attach the receipt (needed above ".Money::paise(self::RECEIPT_REQUIRED_ABOVE_PAISE)->format().').');
            }
            $checked[] = [$category, $spent, $l];
        }

        return DB::transaction(function () use ($employee, $purpose, $checked, $today, $account): Reimbursement {
            $claim = Reimbursement::query()->create([
                'number' => $this->numbers->next(DocumentNumbers::REIMBURSEMENT, $today), 'employee_id' => $employee->id, 'claim_date' => $today->toDateString(),
                'total_paise' => array_sum(array_map(fn ($c) => (int) $c[2]['amount_paise'], $checked)), 'purpose' => trim($purpose), 'submitted_by' => $account->id,
            ]);
            foreach ($checked as [$category, $spent, $l]) {
                $file = filled($l['receipt'] ?? null) ? $this->files->put((string) $l['receipt']) : null;
                if ($file !== null && ! in_array($file->mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
                    throw new BusinessRuleViolation('Receipts must be photos (JPEG, PNG) or PDF.');
                }
                ReimbursementLine::query()->create([
                    'reimbursement_id' => $claim->id, 'expense_category_id' => $category->id, 'spent_on' => $spent->toDateString(),
                    'description' => mb_substr(trim((string) $l['description']), 0, 200), 'amount_paise' => (int) $l['amount_paise'],
                    'receipt_file_id' => $file?->id, 'created_at' => now(),
                ]);
            }
            $this->audit->record('hr.reimbursement.submitted', $claim, null, null, ['total_paise' => $claim->total_paise]);

            return $claim;
        });
    }

    /** Supervisor: the expenses were real and for the mill's work. */
    public function check(Reimbursement $claim, ?string $note = null): Reimbursement
    {
        $who = $this->require(['hr.attendance.review', 'hr.leave.approve'], 'A supervisor (hr.attendance.review) checks claims.');

        return $this->decide($claim, Reimbursement::SUBMITTED, function (Reimbursement $c) use ($who, $note): void {
            $this->notClaimant($c, $who);
            $c->forceFill(['status' => Reimbursement::CHECKED, 'checked_by' => $who->id, 'checked_at' => now(), 'decision_note' => $note])->save();
            $this->audit->record('hr.reimbursement.checked', $c);
        });
    }

    /** Accounts: posts the expenses, owed to the employee. */
    public function approve(Reimbursement $claim, ?string $note = null): Reimbursement
    {
        $who = $this->require(['ledger.post'], 'Accounts (ledger.post) approve claims.');

        return $this->decide($claim, Reimbursement::CHECKED, function (Reimbursement $c) use ($who, $note): void {
            $this->notClaimant($c, $who);
            if ($c->checked_by === $who->id && ! SelfApproval::allowed($who)) {
                throw new BusinessRuleViolation('The person who checked the claim cannot also approve it.');
            }
            $date = CarbonImmutable::now('Asia/Karachi');
            $lines = [];
            foreach ($c->lines()->with('category.ledger')->get() as $l) {
                $lines[] = ['account' => $l->category->ledger, 'dr' => $l->amount_paise, 'memo' => "{$c->number}: {$l->description}"];
            }
            $lines[] = ['account' => $this->chart->system('staff_claims_payable'), 'cr' => $c->total_paise, 'memo' => "{$c->number} · {$c->employee->code} {$c->employee->name()}"];
            $voucher = $this->books->post('journal', $date, $lines, "Staff claim {$c->number}: {$c->purpose}", ['source_type' => 'reimbursement', 'source_id' => $c->id]);
            $c->forceFill(['status' => Reimbursement::APPROVED, 'approved_by' => $who->id, 'approved_at' => now(), 'voucher_id' => $voucher->id,
                'decision_note' => $note ?? $c->decision_note])->save();
            $this->audit->record('hr.reimbursement.approved', $c, null, null, ['voucher' => $voucher->number]);
        });
    }

    public function reject(Reimbursement $claim, string $reason): Reimbursement
    {
        $who = $this->require(['hr.attendance.review', 'hr.leave.approve', 'ledger.post'], 'Only a supervisor or accounts can refuse a claim.');
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for refusing.');
        }

        return DB::transaction(function () use ($claim, $who, $reason): Reimbursement {
            $c = Reimbursement::query()->lockForUpdate()->findOrFail($claim->id);
            if (! in_array($c->status, [Reimbursement::SUBMITTED, Reimbursement::CHECKED], true)) {
                throw new BusinessRuleViolation('Only a claim not yet approved can be refused.');
            }
            $this->notClaimant($c, $who);
            $c->forceFill(['status' => Reimbursement::REJECTED, 'decision_note' => trim($reason)])->save();
            $this->audit->record('hr.reimbursement.rejected', $c, null, null, ['reason' => trim($reason)]);

            return $c;
        });
    }

    public function pay(Reimbursement $claim, LedgerAccount $from, CarbonImmutable $date): Reimbursement
    {
        $who = $this->require(['payment.create'], 'Paying claims needs payment.create.');
        if (! in_array($from->kind, ['cash', 'bank'], true)) {
            throw new BusinessRuleViolation('Pay from a cash or bank account.');
        }

        return $this->decide($claim, Reimbursement::APPROVED, function (Reimbursement $c) use ($from, $date, $who): void {
            $this->notClaimant($c, $who);
            $voucher = $this->books->post('payment', $date, [
                ['account' => $this->chart->system('staff_claims_payable'), 'dr' => $c->total_paise, 'memo' => $c->number],
                ['account' => $from, 'cr' => $c->total_paise, 'memo' => "{$c->number} · {$c->employee->code}"],
            ], "Staff claim {$c->number} paid to {$c->employee->name()}", ['source_type' => 'reimbursement', 'source_id' => $c->id]);
            $c->forceFill(['status' => Reimbursement::PAID, 'paid_from_ledger_id' => $from->id, 'payment_voucher_id' => $voucher->id, 'paid_at' => now()])->save();
            $this->audit->record('hr.reimbursement.paid', $c, null, null, ['voucher' => $voucher->number]);
        });
    }

    private function decide(Reimbursement $claim, string $from, callable $do): Reimbursement
    {
        return DB::transaction(function () use ($claim, $from, $do): Reimbursement {
            $c = Reimbursement::query()->with('employee.person')->lockForUpdate()->findOrFail($claim->id);
            if ($c->status !== $from) {
                throw new BusinessRuleViolation('This claim is '.strtolower(Reimbursement::STATUSES[$c->status] ?? $c->status).'.');
            }
            $do($c);

            return $c;
        });
    }

    private function notClaimant(Reimbursement $c, LoginAccount $who): void
    {
        if ($c->employee->person_id === $who->person_id || ($c->submitted_by === $who->id && ! SelfApproval::allowed($who))) {
            throw new BusinessRuleViolation('You cannot decide on a claim you made or submitted.');
        }
    }

    /** @param  list<string>  $any */
    private function require(array $any, string $message): LoginAccount
    {
        $account = $this->actor->account();
        foreach ($any as $p) {
            if ($account?->hasPermission($p)) {
                return $account;
            }
        }
        throw new BusinessRuleViolation($message);
    }
}
