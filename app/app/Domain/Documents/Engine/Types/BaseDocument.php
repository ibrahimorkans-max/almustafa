<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

use App\Domain\Documents\Engine\DocumentType;
use App\Models\Hr\Employee;

/** Shared parts of the HR document types: the employee block (identifiers masked) and defaults. */
abstract class BaseDocument implements DocumentType
{
    public function oncePerSource(): bool
    {
        return true;
    }

    public function defaultPaper(): string
    {
        return 'a4';
    }

    public function series(): string
    {
        return 'DOC';
    }

    /**
     * Employee fields as printed. CNIC and bank account are stored masked; the full values are filled in
     * at print time only for users allowed to see them.
     *
     * @param  array<string, mixed>|null  $snapshot  the payroll snapshot of the employee (as paid), when there is one
     * @return list<array{0: string, 1: mixed, 2?: string}>
     */
    protected function employeeFields(Employee $e, ?array $snapshot = null, bool $bank = false): array
    {
        $s = $snapshot ?? [];
        $fields = [
            ['employee', ($s['code'] ?? $e->code).' · '.($s['name'] ?? $e->name())],
            ['designation', $s['designation'] ?? $e->designation?->name],
            ['department', $s['department'] ?? $e->department?->name],
            ['cnic', $e->maskedCnic(), 'cnic'],
        ];
        if ($bank) {
            $fields[] = ['iban', $e->bank_account_last4 !== null ? $e->maskedBankAccount() : null, 'iban'];
        }

        return $fields;
    }

    /** @return array{m: int} */
    protected static function money(int $paise): array
    {
        return ['m' => $paise];
    }
}
