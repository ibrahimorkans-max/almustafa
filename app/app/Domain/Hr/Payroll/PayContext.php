<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Models\Hr\Employee;
use App\Models\Hr\SalaryStructure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One employee's month while it is being calculated: the attendance days, the salary structures in
 * force, and the pay lines the steps add. Every line says how it was worked out ("basis"), so the
 * payslip can show it and anyone can recompute it.
 */
final class PayContext
{
    /** @var list<array{code: string, name: string, amount_paise: int, basis: string, meta?: array<string, mixed>}> */
    public array $earnings = [];

    /** @var list<array{code: string, name: string, amount_paise: int, basis: string, meta?: array<string, mixed>}> */
    public array $deductions = [];

    /** @var list<array{code: string, name: string, amount_paise: int, basis: string, meta?: array<string, mixed>}> */
    public array $employer = [];

    /** @var array<string, int|float|string|null> */
    public array $info = [];

    /** @var list<string> */
    public array $warnings = [];

    public readonly int $daysInMonth;

    /** @var Collection<int, SalaryStructure> latest first */
    private Collection $structures;

    /**
     * @param  array<string, array<string, mixed>>  $days  attendance rows keyed by Y-m-d
     * @param  array<string, int|float>  $totals
     */
    public function __construct(
        public readonly Employee $employee,
        public readonly CarbonImmutable $monthStart,
        public readonly array $days,
        public readonly array $totals,
        public readonly PayrollSettings $settings,
    ) {
        $this->daysInMonth = $monthStart->daysInMonth;
        $this->structures = $employee->salaryStructures()->with('lines.component')
            ->where('effective_from', '<=', $this->monthEnd()->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function monthEnd(): CarbonImmutable
    {
        return $this->monthStart->endOfMonth()->startOfDay();
    }

    public function month(): string
    {
        return $this->monthStart->format('Y-m');
    }

    /** The structure in force on a day (null before the first one). */
    public function structureOn(string $day): ?SalaryStructure
    {
        return $this->structures->first(fn (SalaryStructure $s) => $s->effective_from->toDateString() <= $day);
    }

    /** Pay basis of the month: the one in force on the last day, or the latest structure. */
    public function payBasis(): ?string
    {
        return $this->structureOn($this->monthEnd()->toDateString())->pay_basis ?? $this->structures->first()?->pay_basis;
    }

    /** Amount of a pay head in the structure on a day, in paisa (0 if absent). */
    public function amountOn(string $day, string $code): int
    {
        $line = $this->structureOn($day)?->lines->first(fn ($l) => $l->component->code === $code);

        return (int) ($line->amount_paise ?? 0);
    }

    public function setting(string $key): mixed
    {
        return $this->settings->get($key, $this->monthEnd());
    }

    /** @param  array<string, mixed>  $meta */
    public function earn(string $code, string $name, int $amount, string $basis, array $meta = []): void
    {
        if ($amount !== 0) {
            $this->earnings[] = ['code' => $code, 'name' => $name, 'amount_paise' => $amount, 'basis' => $basis] + ($meta === [] ? [] : ['meta' => $meta]);
        }
    }

    /** @param  array<string, mixed>  $meta */
    public function deduct(string $code, string $name, int $amount, string $basis, array $meta = []): void
    {
        if ($amount !== 0) {
            $this->deductions[] = ['code' => $code, 'name' => $name, 'amount_paise' => $amount, 'basis' => $basis] + ($meta === [] ? [] : ['meta' => $meta]);
        }
    }

    public function contribute(string $code, string $name, int $amount, string $basis): void
    {
        if ($amount !== 0) {
            $this->employer[] = ['code' => $code, 'name' => $name, 'amount_paise' => $amount, 'basis' => $basis];
        }
    }

    public function gross(): int
    {
        return array_sum(array_column($this->earnings, 'amount_paise'));
    }

    public function totalDeductions(): int
    {
        return array_sum(array_column($this->deductions, 'amount_paise'));
    }

    /** Sum of the earnings with these codes (e.g. the basic wages a percentage applies to). */
    public function earned(string ...$codes): int
    {
        return array_sum(array_map(fn (array $l) => in_array($l['code'], $codes, true) ? $l['amount_paise'] : 0, $this->earnings));
    }

    /** Days the employee was on the rolls this month. */
    public function employedDays(): int
    {
        return count(array_filter($this->days, fn (array $d) => ! in_array('not_employed', $d['notes'], true)));
    }

    /** Integer rounding of a bcmath value, half up. */
    public static function round(string $value): int
    {
        return (int) bcadd($value, bccomp($value, '0', 6) >= 0 ? '0.5' : '-0.5', 0);
    }

    public static function money(int $paise): string
    {
        return 'Rs '.number_format($paise / 100, 2);
    }
}
