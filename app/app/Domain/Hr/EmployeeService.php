<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Auth\SessionService;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\ClearanceItem;
use App\Models\Hr\Department;
use App\Models\Hr\Designation;
use App\Models\Hr\Employee;
use App\Models\Hr\Shift;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Support\BlindIndex;
use App\Support\Values\PakistanIds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Employee master: joining, changes, notice, exit clearance and exit. Personal identifiers are
 * validated, encrypted and kept unique per entity; revealing them is a separate, audited step.
 */
final class EmployeeService
{
    /** Fields that hr.employee.sensitive.view can reveal. */
    public const SENSITIVE = ['cnic', 'ntn', 'bank_account_number', 'date_of_birth', 'address'];

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly DocumentNumbers $numbers,
        private readonly SessionService $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  full_name, phone, email | person_id; department_id, designation_id,
     *                                      employment_type, joining_date, date_of_birth, address, emergency contact,
     *                                      cnic, ntn, notice_period_days
     */
    public function create(array $data): Employee
    {
        $this->require('hr.employee.edit');
        $data = $this->validated($data, null);

        return DB::transaction(function () use ($data): Employee {
            if (! empty($data['person_id'])) {
                $person = Person::query()->findOrFail($data['person_id']);
                if (Employee::query()->where('person_id', $person->id)->where('status', '!=', Employee::EXITED)->exists()) {
                    throw new BusinessRuleViolation('This person is already employed here.');
                }
            } else {
                $person = Person::query()->create([
                    'full_name' => trim((string) $data['full_name']),
                    'phone' => PakistanIds::normalizeMobile($data['phone'] ?? null),
                    'email' => $data['email'] ?? null,
                ]);
            }
            $employee = new Employee(array_intersect_key($data, array_flip((new Employee)->getFillable())));
            $employee->person_id = $person->id;
            $employee->code = $this->numbers->nextCode(DocumentNumbers::EMPLOYEE);
            $employee->status = Employee::ACTIVE;
            $employee->save();

            return $employee;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Employee $employee, array $data): Employee
    {
        $this->require('hr.employee.edit');
        if ($employee->status === Employee::EXITED) {
            throw new BusinessRuleViolation('An exited employee is kept as history and cannot be changed.');
        }
        $data = $this->validated($data, $employee);

        return DB::transaction(function () use ($employee, $data): Employee {
            if (isset($data['full_name']) || array_key_exists('phone', $data) || array_key_exists('email', $data)) {
                $employee->person->fill(array_filter([
                    'full_name' => isset($data['full_name']) ? trim((string) $data['full_name']) : null,
                    'phone' => array_key_exists('phone', $data) ? PakistanIds::normalizeMobile($data['phone']) : null,
                    'email' => $data['email'] ?? null,
                ], fn ($v) => $v !== null))->save();
            }
            $employee->fill(array_intersect_key($data, array_flip($employee->getFillable())));
            $employee->save();

            return $employee;
        });
    }

    /** Starts the notice period and opens the exit clearance checklist. */
    public function giveNotice(Employee $employee, CarbonImmutable $givenOn, string $reason, ?CarbonImmutable $lastWorkingDay = null): Employee
    {
        $this->require('hr.employee.edit');
        if ($employee->status !== Employee::ACTIVE) {
            throw new BusinessRuleViolation('Only an active employee can be put on notice.');
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessRuleViolation('Give the reason for leaving.');
        }
        $last = $lastWorkingDay ?? $givenOn->addDays($employee->notice_period_days);
        if ($last->lessThan($givenOn)) {
            throw new BusinessRuleViolation('The last working day cannot be before the notice date.');
        }

        return DB::transaction(function () use ($employee, $givenOn, $reason, $last): Employee {
            $employee->forceFill([
                'status' => Employee::ON_NOTICE, 'notice_given_on' => $givenOn, 'last_working_day' => $last, 'exit_reason' => $reason,
            ])->save();
            $open = $employee->clearanceItems()->whereNull('done_at')->pluck('item')->all();
            foreach (array_diff(ClearanceItem::DEFAULT_ITEMS, $open) as $item) {
                $employee->clearanceItems()->create(['item' => $item]);
            }

            return $employee;
        });
    }

    /** Back to active (e.g. resignation withdrawn); open checklist items are kept as history. */
    public function withdrawNotice(Employee $employee, string $reason): Employee
    {
        $this->require('hr.employee.edit');
        if ($employee->status !== Employee::ON_NOTICE) {
            throw new BusinessRuleViolation('The employee is not on notice.');
        }
        $employee->forceFill(['status' => Employee::ACTIVE, 'notice_given_on' => null, 'last_working_day' => null, 'exit_reason' => null])->save();
        $this->audit->record('hr.employee.notice_withdrawn', $employee, meta: ['reason' => $reason]);

        return $employee;
    }

    public function completeClearance(ClearanceItem $item, ?string $note): ClearanceItem
    {
        $this->require('hr.employee.edit');
        if ($item->done_at !== null) {
            throw new BusinessRuleViolation('This item is already cleared.');
        }
        $item->forceFill(['done_at' => now(), 'done_by' => $this->actor->accountId(), 'note' => $note])->save();

        return $item;
    }

    /**
     * Exit after the clearance is complete. The person's logins in this entity are revoked at once.
     * Final settlement is computed by payroll (later HR phases).
     */
    public function markExited(Employee $employee, CarbonImmutable $exitedOn): Employee
    {
        $this->require('hr.employee.edit');
        if ($employee->status !== Employee::ON_NOTICE) {
            throw new BusinessRuleViolation('Put the employee on notice first.');
        }
        $open = $employee->clearanceItems()->whereNull('done_at')->pluck('item')->all();
        if ($open !== []) {
            throw new BusinessRuleViolation('Clearance is not complete: '.implode('; ', $open).'.');
        }

        return DB::transaction(function () use ($employee, $exitedOn): Employee {
            $employee->forceFill(['status' => Employee::EXITED, 'exited_on' => $exitedOn])->save();
            foreach ($employee->loginAccounts() as $account) {
                if ($account->status === LoginAccount::STATUS_REVOKED) {
                    continue;
                }
                $account->forceFill(['status' => LoginAccount::STATUS_REVOKED])->save();
                $ended = $this->sessions->revokeAllFor($account, 'account_revoked');
                $this->audit->record('auth.account.revoked', $account, ['status' => 'active'], ['status' => 'revoked'],
                    ['reason' => 'employee exited', 'employee_id' => $employee->id, 'sessions_ended' => $ended]);
            }

            return $employee;
        });
    }

    /** Full value of a protected field for someone allowed to see it; every reveal is in the audit log. */
    public function reveal(Employee $employee, string $field): ?string
    {
        $this->require('hr.employee.sensitive.view');
        if (! in_array($field, self::SENSITIVE, true)) {
            throw new BusinessRuleViolation('Unknown field.');
        }
        $this->audit->record('hr.employee.sensitive_revealed', $employee, meta: ['field' => $field]);

        return $employee->getAttribute($field);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, ?Employee $existing): array
    {
        if ($existing === null && empty($data['person_id']) && mb_strlen(trim((string) ($data['full_name'] ?? ''))) < 2) {
            throw new BusinessRuleViolation('Enter the employee\'s name.');
        }
        if (isset($data['employment_type']) && ! array_key_exists($data['employment_type'], Employee::TYPES)) {
            throw new BusinessRuleViolation('Unknown employment type.');
        }
        if ($existing === null && empty($data['employment_type'])) {
            throw new BusinessRuleViolation('Choose the employment type.');
        }
        if ($existing === null && empty($data['joining_date'])) {
            throw new BusinessRuleViolation('Enter the joining date.');
        }
        foreach (['department_id' => Department::class, 'designation_id' => Designation::class, 'shift_id' => Shift::class] as $key => $model) {
            if (! empty($data[$key]) && ! $model::query()->whereKey($data[$key])->exists()) {
                throw new BusinessRuleViolation('Unknown '.str_replace('_id', '', $key).'.');
            }
        }
        if (! empty($data['cnic'])) {
            $data['cnic'] = preg_replace('/\D+/', '', (string) $data['cnic']);
            if (! PakistanIds::isValidCnic($data['cnic'])) {
                throw new BusinessRuleViolation('The CNIC is not valid (13 digits, e.g. 31202-1234567-1).');
            }
            $this->assertUnique('cnic_index', BlindIndex::of($data['cnic'], 'cnic'), $existing, 'CNIC');
        }
        if (! empty($data['ntn'])) {
            $data['ntn'] = (string) PakistanIds::digits((string) $data['ntn']);
            if (! PakistanIds::isValidNtn($data['ntn'])) {
                throw new BusinessRuleViolation('The NTN is not valid (1234567-8, or the CNIC for an individual).');
            }
            $this->assertUnique('ntn_index', BlindIndex::of($data['ntn'], 'ntn'), $existing, 'NTN');
        }
        if (! empty($data['date_of_birth'])) {
            $dob = CarbonImmutable::parse((string) $data['date_of_birth']);
            if ($dob->greaterThan(now()->subYears(15))) {
                throw new BusinessRuleViolation('The date of birth makes the employee younger than 15, which the Punjab Restriction on Employment of Children Act 2016 does not allow.');
            }
            $data['date_of_birth'] = $dob->toDateString();
        }
        if (isset($data['notice_period_days']) && ((int) $data['notice_period_days'] < 0 || (int) $data['notice_period_days'] > 180)) {
            throw new BusinessRuleViolation('Notice period must be between 0 and 180 days.');
        }

        return $data;
    }

    private function assertUnique(string $column, ?string $index, ?Employee $existing, string $label): void
    {
        $taken = Employee::query()->withoutGlobalScope('not_archived')->where($column, $index)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->value('code');
        if ($taken !== null) {
            throw new BusinessRuleViolation("This {$label} is already recorded for employee {$taken}.");
        }
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}
