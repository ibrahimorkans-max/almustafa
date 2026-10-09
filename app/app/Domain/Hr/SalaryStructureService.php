<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\Employee;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\SalaryStructure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated salary structures. A change never edits the old version: it adds a new one with
 * its own effective date and reason. A new version for the same date as an existing one replaces it
 * from then on (linked through supersedes_id); both remain in the history.
 */
final class SalaryStructureService
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, int>  $amountsPaise  component code => amount in paise (per month / per day / per unit)
     * @param  array<string, string>  $units  component code => unit for piece-rate lines (bag, quintal, ...)
     */
    public function create(Employee $employee, CarbonImmutable $effectiveFrom, string $payBasis, array $amountsPaise, string $reason, array $units = []): SalaryStructure
    {
        if (! ($this->actor->account()?->hasPermission('hr.salary.edit') ?? false)) {
            throw new BusinessRuleViolation("You need 'hr.salary.edit' to change salaries.");
        }
        if ($employee->status === Employee::EXITED) {
            throw new BusinessRuleViolation('An exited employee\'s salary cannot be changed.');
        }
        if (! array_key_exists($payBasis, SalaryStructure::BASES)) {
            throw new BusinessRuleViolation('Unknown pay basis.');
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessRuleViolation('Give the reason for the change (e.g. joining, annual increment, correction).');
        }
        if ($effectiveFrom->lessThan(CarbonImmutable::parse($employee->joining_date)->startOfDay())) {
            throw new BusinessRuleViolation('The salary cannot start before the joining date.');
        }

        $amounts = array_filter($amountsPaise, fn ($v) => $v !== null && (int) $v !== 0);
        foreach ($amounts as $code => $paise) {
            if (! is_int($paise) && ! (is_string($paise) && ctype_digit($paise))) {
                throw new BusinessRuleViolation("The amount for {$code} must be a whole number of paise.");
            }
            if ((int) $paise < 0) {
                throw new BusinessRuleViolation("The amount for {$code} cannot be negative.");
            }
        }
        $components = SalaryComponent::query()->whereIn('code', array_keys($amounts))->where('status', 'active')->get()->keyBy('code');
        $unknown = array_diff(array_keys($amounts), $components->keys()->all());
        if ($unknown !== []) {
            throw new BusinessRuleViolation('Unknown or inactive pay heads: '.implode(', ', $unknown).'.');
        }
        $earnings = $components->filter(fn (SalaryComponent $c) => $c->kind === SalaryComponent::EARNING);
        if ($earnings->isEmpty()) {
            throw new BusinessRuleViolation('Add at least one earning (for example Basic).');
        }
        if ($payBasis !== 'piece_rate' && (int) ($amounts['BASIC'] ?? 0) <= 0) {
            throw new BusinessRuleViolation($payBasis === 'daily' ? 'Enter the daily rate as Basic.' : 'Enter the monthly Basic.');
        }
        if ($payBasis === 'piece_rate' && $earnings->keys()->diff(array_keys(array_filter($units)))->isNotEmpty()) {
            throw new BusinessRuleViolation('For piece rate, give the unit (bag, quintal, ...) of every earning.');
        }

        return DB::transaction(function () use ($employee, $effectiveFrom, $payBasis, $amounts, $components, $reason, $units): SalaryStructure {
            $sameDay = $employee->salaryStructures()->where('effective_from', $effectiveFrom->toDateString())
                ->orderByDesc('created_at')->orderByDesc('id')->value('id');
            $now = now();
            $structure = $employee->salaryStructures()->create([
                'effective_from' => $effectiveFrom->toDateString(), 'pay_basis' => $payBasis, 'reason' => trim($reason),
                'supersedes_id' => $sameDay, 'created_at' => $now,
            ]);
            foreach ($amounts as $code => $paise) {
                $structure->lines()->create([
                    'salary_component_id' => $components[$code]->id, 'amount_paise' => (int) $paise,
                    'unit' => $payBasis === 'piece_rate' ? ($units[$code] ?? null) : null, 'created_at' => $now,
                ]);
            }
            // Amounts stay in the (append-only) structure rows, readable with hr.salary.view only; the
            // audit entry points to them without copying confidential pay into the audit log.
            $this->audit->record('hr.salary.changed', $employee, null, [
                'effective_from' => $effectiveFrom->toDateString(), 'pay_basis' => $payBasis, 'components' => array_keys($amounts),
            ], ['reason' => $reason, 'structure_id' => $structure->id, 'supersedes_id' => $sameDay]);

            return $structure->load('lines.component');
        });
    }
}
