<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine;

use App\Domain\Shared\BusinessRuleViolation;

/** The registry of document types the engine can produce. */
final class DocumentTypes
{
    /** @var array<string, class-string<DocumentType>> */
    public const TYPES = [
        'payslip' => Types\PayslipDocument::class,
        'advance_voucher' => Types\AdvanceVoucherDocument::class,
        'bonus_slip' => Types\BonusSlipDocument::class,
        'cash_receipt' => Types\CashReceiptDocument::class,
        'attendance_summary' => Types\AttendanceSummaryDocument::class,
        'payroll_register' => Types\PayrollRegisterDocument::class,
        'salary_certificate' => Types\SalaryCertificateDocument::class,
        'employment_letter' => Types\EmploymentLetterDocument::class,
        'reimbursement_voucher' => Types\ReimbursementVoucherDocument::class,
        'contractor_bill' => Types\ContractorBillDocument::class,
    ];

    public static function get(string $key): DocumentType
    {
        $class = self::TYPES[$key] ?? throw new BusinessRuleViolation("Unknown document type {$key}.");

        return app($class);
    }

    /** @return array<string, string> key => label */
    public static function options(): array
    {
        return array_map(fn (string $class) => app($class)->label(), self::TYPES);
    }
}
