<?php

declare(strict_types=1);

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Documents\RecordDocuments;
use App\Domain\Hr\Payroll\AdvanceService;
use App\Domain\Hr\Payroll\PayrollService;
use App\Domain\Hr\SalaryStructureService;
use App\Domain\Hr\SalaryVouchers;
use App\Domain\Reports\Balances;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\EmployeeResource\Pages\CreateEmployee;
use App\Models\Accounts\VoucherLine;
use App\Models\Hr\Employee;
use App\Models\Hr\SalaryStructure;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('SVC'));
    $this->e = $this->f->employee(['joining_date' => '2026-01-01']);
    $this->f->as($this->f->hr);
    app(SalaryStructureService::class)->create($this->e, CarbonImmutable::parse('2026-01-01'), 'monthly', ['BASIC' => 30_000_00], 'Joining');
    $this->cash = app(ChartOfAccounts::class)->system('cash');
    $this->month = now('Asia/Karachi')->format('Y-m');
});

it('pays a month\'s salary on a voucher, takes the advance instalment off, and never pays the month twice', function () {
    // An advance of Rs 10,000, Rs 4,000 a month.
    $svc = app(AdvanceService::class);
    $adv = $svc->request($this->e, 'advance', 10_000_00, 4_000_00, $this->month, 'Medical');
    $this->f->as($this->f->accountant);
    $svc->approve($adv, null);
    $this->f->as($this->f->hr);
    $adv = $svc->disburse($adv->fresh(), $this->cash, CarbonImmutable::now('Asia/Karachi'));

    $s = SalaryVouchers::suggestion($this->e, $this->month);
    expect($s)->toMatchArray(['basis' => 'monthly', 'salary_paise' => 30_000_00, 'advance_id' => $adv->id, 'instalment_paise' => 4_000_00, 'paid' => false]);

    $v = app(SalaryVouchers::class)->create(['employee_id' => $this->e->id, 'kind' => 'salary', 'month' => $this->month, 'gross' => '30000',
        'advance_id' => $adv->id, 'advance' => '4000', 'other_deduction' => '500', 'other_deduction_note' => 'Canteen',
        'mode' => 'cash', 'ledger_id' => $this->cash->id]);

    expect($v->net_paise)->toBe(25_500_00)->and($v->number)->toContain('/SV/')
        ->and(VoucherLine::query()->where('voucher_id', $v->voucher_id)->with('account')->orderBy('line_no')->get()->map(fn ($l) => [$l->account->code, (int) $l->dr_paise, (int) $l->cr_paise])->all())
        ->toBe([['EXP-SALARY', 30_000_00, 0], ['STAFF-ADV', 0, 4_000_00], ['STAFF-DED', 0, 500_00], ['CASH', 0, 25_500_00]])
        ->and($adv->fresh()->balancePaise())->toBe(6_000_00)
        ->and(SalaryVouchers::monthPaid($this->e, $this->month))->toBeTrue()
        // Not twice, and not again in the payroll run.
        ->and(fn () => app(SalaryVouchers::class)->create(['employee_id' => $this->e->id, 'kind' => 'salary', 'month' => $this->month, 'gross' => '30000', 'mode' => 'cash', 'ledger_id' => $this->cash->id]))
        ->toThrow(BusinessRuleViolation::class, 'already paid')
        ->and(app(PayrollService::class)->employees($this->month)->pluck('id'))->not->toContain($this->e->id);

    // A bonus is not a salary: allowed in the same month. A cheque needs its number.
    expect(fn () => app(SalaryVouchers::class)->create(['employee_id' => $this->e->id, 'kind' => 'bonus', 'gross' => '5000', 'mode' => 'cheque', 'ledger_id' => $this->cash->id]))
        ->toThrow(BusinessRuleViolation::class);
    app(SalaryVouchers::class)->create(['employee_id' => $this->e->id, 'kind' => 'bonus', 'month' => $this->month, 'gross' => '5000', 'mode' => 'cash', 'ledger_id' => $this->cash->id]);

    // Employee balances: paid this month, the advance still outstanding.
    $row = collect(app(Balances::class)->employees($this->month))->firstWhere('employee_id', $this->e->id);
    expect($row)->toMatchArray(['salary_paise' => 30_000_00, 'paid_paise' => 30_000_00, 'due_paise' => 0, 'advance_paise' => 6_000_00]);
    $next = collect(app(Balances::class)->employees(now('Asia/Karachi')->addMonthNoOverflow()->format('Y-m')))->firstWhere('employee_id', $this->e->id);
    expect($next)->toMatchArray(['paid_paise' => 0, 'due_paise' => 30_000_00]);

    expect(app(RecordDocuments::class)->pdf('salary_voucher', $v))->toStartWith('%PDF');
});

it('takes the salary while adding an employee', function () {
    $this->actingAs($this->f->hr, 'web')->withSession(Make::webSession($this->f->hr));
    $this->f->as($this->f->hr);
    Livewire::test(CreateEmployee::class)
        ->fillForm(['full_name' => 'Abdul Ghani', 'employment_type' => 'permanent', 'joining_date' => '2026-02-01', 'notice_period_days' => 30,
            'cnic' => HrFixtures::cnic(), 'salary_basis' => 'monthly', 'salary_amount' => '32000'])
        ->call('create')->assertHasNoFormErrors();

    $e = Employee::query()->whereHas('person', fn ($q) => $q->where('full_name', 'Abdul Ghani'))->sole();
    $s = SalaryStructure::query()->with('lines.component')->where('employee_id', $e->id)->sole();
    expect($s->pay_basis)->toBe('monthly')->and($s->totalPaise('earning'))->toBe(32_000_00)->and($s->effective_from->toDateString())->toBe('2026-02-01');

    $this->get('/admin/hr/salary-vouchers/create')->assertOk();
    $this->get('/admin/hr/employee-balances')->assertOk()->assertSee('Abdul Ghani');
});
