<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Contracts\ExplainsCheckerRule;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Audit\AuditLogger;
use App\Models\ApprovalRequest;
use App\Models\Hr\Employee;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Set or change an employee's salary account — always maker-checker: HR requests, someone who
 * approves payroll checks it. A swapped account number is the usual way to divert wages.
 * The number is encrypted inside the request payload; screens and the audit log see the last 4.
 */
final class EmployeeBankAccountChange implements ApprovalAction, ExplainsCheckerRule
{
    public const KEY = 'hr.employee.bank_account.change';

    public function __construct(private readonly AuditLogger $audit) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'hr.employee.edit';
    }

    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        return $checker->hasPermission('hr.payroll.approve');
    }

    public function whyCheckerMayNotDecide(LoginAccount $checker, ApprovalRequest $request): string
    {
        return "Salary account changes are approved by someone with 'hr.payroll.approve' (accounts / owner).";
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $data = Validator::make($payload, [
            'employee_id' => ['required', 'uuid'],
            'account_holder' => ['required', 'string', 'max:120'],
            'account_number' => ['required', fn ($a, $value, $fail) => PakistanIds::isValidIban((string) PakistanIds::normalizeIban((string) $value))
                ? null : $fail('Enter the IBAN (PK and 22 characters); its check digits do not match.')],
            'bank_name' => ['nullable', 'string', 'max:120'],
        ])->validate();
        $data['account_number'] = (string) PakistanIds::normalizeIban((string) $data['account_number']);

        $employee = Employee::query()->find($data['employee_id']) ?? throw ValidationException::withMessages(['employee_id' => 'Employee not found.']);
        if ($employee->status === Employee::EXITED) {
            throw ValidationException::withMessages(['employee_id' => 'The employee has exited.']);
        }

        return [
            'employee_id' => $employee->id,
            'employee' => $employee->code.' '.$employee->name(),
            'account_holder' => $data['account_holder'],
            'account_number_encrypted' => Crypt::encryptString($data['account_number']),
            'account_number_masked' => 'XXXXXX'.substr($data['account_number'], -4),
            'previous_account_masked' => $employee->bank_account_last4 ? 'XXXXXX'.$employee->bank_account_last4 : null,
            'bank_code' => PakistanIds::bankCodeOfIban($data['account_number']),
            'bank_name' => $data['bank_name'] ?? null,
        ];
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        $p = $request->payload;
        $employee = Employee::query()->lockForUpdate()->find($p['employee_id']) ?? throw new ApprovalDenied('Employee no longer exists.');
        if ($employee->status === Employee::EXITED) {
            throw new ApprovalDenied('The employee has exited.');
        }
        $employee->forceFill([
            'bank_account_number' => Crypt::decryptString((string) $p['account_number_encrypted']),
            'bank_name' => $p['bank_name'], 'bank_account_holder' => $p['account_holder'],
            'bank_approval_request_id' => $request->id,
        ])->save();
        $this->audit->record('hr.employee.bank_account_changed', $employee, meta: [
            'account' => $p['account_number_masked'], 'previous' => $p['previous_account_masked'], 'bank_code' => $p['bank_code'],
            'approval_request_id' => $request->id,
        ]);

        return ['employee_id' => $employee->id, 'account' => $p['account_number_masked']];
    }
}
