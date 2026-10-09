<?php

declare(strict_types=1);

use App\Domain\Hr\SalaryStructureService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\SalaryStructure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('SAL'));
    $this->e = $this->f->employee(['joining_date' => '2026-04-01']);
    $this->svc = app(SalaryStructureService::class);
    $this->f->as($this->f->hr);
});

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

it('keeps every version and finds the one in force on any day', function () {
    $this->svc->create($this->e, day('2026-04-01'), 'monthly', ['BASIC' => 15_000_00, 'HRA' => 4_000_00, 'PF' => 1_800_00], 'Joining');
    $this->svc->create($this->e, day('2026-10-01'), 'monthly', ['BASIC' => 16_500_00, 'HRA' => 4_400_00, 'PF' => 1_980_00], 'Annual increment');

    expect($this->e->salaryOn(day('2026-03-31')))->toBeNull()
        ->and($this->e->salaryOn(day('2026-09-30'))->totalPaise(SalaryComponent::EARNING))->toBe(19_000_00)
        ->and($this->e->salaryOn(day('2026-10-01'))->totalPaise(SalaryComponent::EARNING))->toBe(20_900_00)
        ->and($this->e->salaryOn(day('2026-10-01'))->totalPaise(SalaryComponent::DEDUCTION))->toBe(1_980_00)
        ->and(SalaryStructure::query()->count())->toBe(2);
});

it('corrects a version for the same date by adding a new one that replaces it, keeping both', function () {
    $wrong = $this->svc->create($this->e, day('2026-04-01'), 'monthly', ['BASIC' => 1_500_00], 'Joining');
    $right = $this->svc->create($this->e, day('2026-04-01'), 'monthly', ['BASIC' => 15_000_00], 'Correction: one zero was missing');

    expect($right->supersedes_id)->toBe($wrong->id)
        ->and($this->e->salaryOn(day('2026-05-01'))->id)->toBe($right->id)
        ->and(SalaryStructure::query()->whereKey($wrong->id)->exists())->toBeTrue();
});

it('supports daily wages and piece rates with units', function () {
    $daily = $this->f->employee(['full_name' => 'Daily Worker', 'employment_type' => 'daily_wage']);
    $piece = $this->f->employee(['full_name' => 'Loader', 'employment_type' => 'piece_rate']);
    $this->f->as($this->f->hr);

    $d = $this->svc->create($daily, day('2026-04-01'), 'daily', ['BASIC' => 550_00], 'Joining');
    $p = $this->svc->create($piece, day('2026-04-01'), 'piece_rate', ['BASIC' => 3_50], 'Joining', ['BASIC' => 'bag']);

    expect($d->lines->first()->amount_paise)->toBe(550_00)
        ->and($p->lines->first()->unit)->toBe('bag')
        ->and(fn () => $this->svc->create($piece, day('2026-05-01'), 'piece_rate', ['BASIC' => 3_50], 'Raise'))
        ->toThrow(BusinessRuleViolation::class, 'give the unit');
});

it('refuses incomplete or impossible structures', function () {
    expect(fn () => $this->svc->create($this->e, day('2026-03-01'), 'monthly', ['BASIC' => 10_000_00], 'Joining'))->toThrow(BusinessRuleViolation::class, 'before the joining date')
        ->and(fn () => $this->svc->create($this->e, day('2026-04-01'), 'monthly', ['HRA' => 1_000_00], 'Joining'))->toThrow(BusinessRuleViolation::class, 'monthly Basic')
        ->and(fn () => $this->svc->create($this->e, day('2026-04-01'), 'monthly', ['PF' => 1_000_00], 'Joining'))->toThrow(BusinessRuleViolation::class, 'at least one earning')
        ->and(fn () => $this->svc->create($this->e, day('2026-04-01'), 'monthly', ['BASIC' => 10_000_00, 'BONUSX' => 5], 'Joining'))->toThrow(BusinessRuleViolation::class, 'BONUSX')
        ->and(fn () => $this->svc->create($this->e, day('2026-04-01'), 'monthly', ['BASIC' => 10_000_00], ''))->toThrow(BusinessRuleViolation::class, 'reason');

    $this->f->as($this->f->accountant); // may view salaries, not change them
    expect(fn () => $this->svc->create($this->e, day('2026-04-01'), 'monthly', ['BASIC' => 10_000_00], 'Joining'))->toThrow(BusinessRuleViolation::class, 'hr.salary.edit');
});

it('audits the change without copying pay figures into the audit log', function () {
    $this->svc->create($this->e, day('2026-04-01'), 'monthly', ['BASIC' => 15_432_00], 'Joining');
    $log = Make::system(fn () => AuditLog::query()->where('action', 'hr.salary.changed')->sole());
    expect($log->new_values['components'])->toBe(['BASIC'])
        ->and(json_encode([$log->new_values, $log->meta]))->not->toContain('1543200');
});

it('rejects any update or delete of a salary version at the database (append-only)', function () {
    $s = $this->svc->create($this->e, day('2026-04-01'), 'monthly', ['BASIC' => 15_000_00], 'Joining');
    expect(fn () => $s->forceFill(['reason' => 'x'])->save())->toThrow(LogicException::class);
    // Last: on PostgreSQL the rejected statement aborts the test transaction.
    expect(fn () => DB::table('salary_structure_lines')->where('salary_structure_id', $s->id)->update(['amount_paise' => 1]))->toThrow(Exception::class);
});
