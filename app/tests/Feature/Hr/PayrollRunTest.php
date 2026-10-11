<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Approvals\Actions\ManualAttendance;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Hr\Payroll\AdvanceService;
use App\Domain\Hr\Payroll\PayAdjustmentService;
use App\Domain\Hr\Payroll\PayrollService;
use App\Domain\Hr\Payroll\PayrollSettings;
use App\Domain\Hr\Payroll\SalaryPaymentService;
use App\Domain\Hr\Payroll\StatutoryReports;
use App\Domain\Hr\Payroll\Steps\IncomeTaxStep;
use App\Domain\Hr\Payroll\WageEngine;
use App\Domain\Hr\SalaryStructureService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\StatutoryReportsPage;
use App\Filament\Resources\Hr\AllowanceRuleResource\Pages\ManageAllowanceRules;
use App\Filament\Resources\Hr\PayAdjustmentResource\Pages\ListPayAdjustments;
use App\Filament\Resources\Hr\PayrollRunResource\Pages\ListPayrollRuns;
use App\Filament\Resources\Hr\PayrollRunResource\Pages\ViewPayrollRun;
use App\Filament\Resources\Hr\PayrollRunResource\RelationManagers\ItemsRelationManager;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\VoucherLine;
use App\Models\Hr\AllowanceRule;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveType;
use App\Models\Hr\PayAdjustment;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\Payslip;
use App\Models\Hr\Shift;
use App\Models\LoginAccount;
use App\Support\Export\XlsxWriter;
use App\Support\Values\PakistanIds;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('PRL'));
    $this->gen = Shift::query()->where('code', 'GEN')->value('id');
    $this->a = prEmployee($this, 30000);               // monthly Rs 30,000
    $this->b = prEmployee($this, 150000);              // monthly Rs 150,000 (pays income tax)
    $this->svc = app(PayrollService::class);
    $this->cash = app(ChartOfAccounts::class)->system('cash');
});

function prEmployee(object $t, int $basicRupees, string $basis = 'monthly'): Employee
{
    $t->f->as($t->f->hr);
    $e = $t->f->employee(['joining_date' => '2026-01-01']);
    $e->forceFill(['shift_id' => $t->gen])->save();
    app(SalaryStructureService::class)->create($e, CarbonImmutable::parse('2026-01-01'), $basis, ['BASIC' => $basicRupees * 100], 'Joining');

    return $e->fresh();
}

/** Valid in / out punches 09:00–18:00 for every Monday–Saturday of the month except $skip. */
function prPunchMonth(Employee $e, string $month, array $skip = []): void
{
    $start = CarbonImmutable::parse($month.'-01', 'Asia/Karachi');
    for ($d = $start; $d->month === $start->month; $d = $d->addDay()) {
        if ($d->isSunday() || in_array($d->toDateString(), $skip, true)) {
            continue;
        }
        foreach ([['in', '09:00'], ['out', '18:00']] as [$kind, $time]) {
            $at = CarbonImmutable::parse($d->toDateString().' '.$time, 'Asia/Karachi')->utc()->format('Y-m-d H:i:s.v');
            AttendanceLog::query()->create(['employee_id' => $e->id, 'kind' => $kind, 'source' => 'app', 'occurred_at' => $at, 'received_at' => $at,
                'time_check' => 'verified', 'client_event_id' => (string) Str::uuid(), 'status' => 'valid', 'reasons' => [], 'created_at' => now()]);
        }
    }
}

function prLine(array $pay, string $section, string $code): int
{
    return array_sum(array_map(fn ($l) => $l['code'] === $code ? $l['amount_paise'] : 0, $pay[$section]));
}

describe('allowances, bonuses and deductions', function () {
    it('pays allowances by rule only to those they apply to', function () {
        $this->f->as($this->f->owner);
        $prod = Department::query()->where('code', 'PROD')->value('id');
        $this->a->forceFill(['department_id' => $prod])->save();
        $this->b->forceFill(['department_id' => Department::query()->where('code', 'ACC')->value('id')])->save();
        foreach ([
            ['CONV', 'Conveyance', 'fixed', 3000_00, 0, true, null, null, null],
            ['MEDICAL', 'Medical allowance', 'pct_basic', 0, 1000, true, null, null, null],
            ['MEAL', 'Meal allowance', 'per_day', 100_00, 0, true, null, null, [$prod]],
            ['ATT', 'Attendance allowance', 'attendance', 2000_00, 0, true, 1, null, null],
        ] as [$code, $name, $method, $amount, $bp, $prorate, $maxAbsent, $maxLate, $depts]) {
            AllowanceRule::query()->create(['code' => $code, 'name' => $name, 'method' => $method, 'amount_paise' => $amount, 'rate_bp' => $bp,
                'prorate' => $prorate, 'max_absent_days' => $maxAbsent, 'max_late_days' => $maxLate, 'departments' => $depts, 'effective_from' => '2026-01-01']);
        }
        prPunchMonth($this->a, '2026-09', ['2026-09-07', '2026-09-08']);   // two days absent
        prPunchMonth($this->b, '2026-09');
        $engine = app(WageEngine::class);
        $a = $engine->calculate($this->a->fresh(), '2026-09');
        $b = $engine->calculate($this->b->fresh(), '2026-09');

        expect(prLine($a, 'earnings', 'BASIC'))->toBe(28_000_00)            // 28 of 30 days
            ->and(prLine($a, 'earnings', 'CONV'))->toBe(2_800_00)          // prorated
            ->and(prLine($a, 'earnings', 'MEDICAL'))->toBe(2_800_00)       // 10 % of Basic earned
            ->and(prLine($a, 'earnings', 'MEAL'))->toBe(24_00_00)          // 24 days present × 100
            ->and(prLine($a, 'earnings', 'ATT'))->toBe(0)                  // 2 absences > 1 allowed
            ->and(prLine($b, 'earnings', 'MEAL'))->toBe(0)                 // not in Production
            ->and(prLine($b, 'earnings', 'ATT'))->toBe(2_000_00);
    });

    it('counts bonuses and fines only after a second person approves, caps fines, and makes festival bonuses in a batch', function () {
        $adj = app(PayAdjustmentService::class);
        $this->f->as($this->f->hr);
        $perf = $adj->request($this->a, '2026-09', 'bonus', 'PERF', 'Performance bonus', 5_000_00, 'Best loader of the month');
        $fine = $adj->request($this->a, '2026-09', 'fine', 'FINE', 'Fine', 5_000_00, 'Damaged 3 bags by careless loading (show-cause 4 Sep)');
        expect(fn () => $adj->approve($perf))->toThrow(BusinessRuleViolation::class, 'hr.payroll.approve');
        prPunchMonth($this->a, '2026-09');
        $pay = app(WageEngine::class)->calculate($this->a->fresh(), '2026-09');
        expect(prLine($pay, 'earnings', 'PERF'))->toBe(0)->and(implode(' ', $pay['warnings']))->toContain('waiting for approval');

        $this->f->as($this->f->accountant);
        $adj->approve($perf);
        $adj->approve($fine);
        $pay = app(WageEngine::class)->calculate($this->a->fresh(), '2026-09');
        // Fines may not exceed 3 % of wages: 3 % of 35,000 = 1,050.
        expect(prLine($pay, 'earnings', 'PERF'))->toBe(5_000_00)->and(prLine($pay, 'deductions', 'FINE'))->toBe(1_050_00)
            ->and(implode(' ', $pay['warnings']))->toContain('Fine Rs 5,000.00 cut to Rs 1,050.00');

        $this->f->as($this->f->hr);
        $this->b->forceFill(['joining_date' => '2026-04-01'])->save();
        $batch = $adj->festivalBonus('2026-09', 'Eid ul Adha bonus', 10000, 3, true, 'One month\'s Basic, by service');
        expect($batch['count'])->toBe(2)
            ->and(PayAdjustment::query()->where('batch_id', $batch['batch_id'])->where('employee_id', $this->a->id)->value('amount_paise'))->toBe(22_500_00) // 9/12 of 30,000
            ->and(PayAdjustment::query()->where('batch_id', $batch['batch_id'])->where('employee_id', $this->b->id)->value('amount_paise'))->toBe(75_000_00); // 6/12 of 150,000
        $this->f->as($this->f->accountant);
        expect($adj->approveBatch($batch['batch_id']))->toBe(2);
    });

    it('deducts EOBI and pays the employer shares of EOBI and PESSI within the ceiling', function () {
        prPunchMonth($this->a, '2026-09');
        prPunchMonth($this->b, '2026-09');
        $a = app(WageEngine::class)->calculate($this->a->fresh(), '2026-09');
        $b = app(WageEngine::class)->calculate($this->b->fresh(), '2026-09');

        expect(prLine($a, 'deductions', 'EOBI'))->toBe(370_00)            // 1 % of 37,000
            ->and(prLine($a, 'employer', 'EOBI_ER'))->toBe(1_850_00)      // 5 %
            ->and(prLine($a, 'employer', 'PESSI_ER'))->toBe(1_800_00)     // 6 % of 30,000 (within the 40,000 ceiling)
            ->and(prLine($b, 'employer', 'PESSI_ER'))->toBe(0)            // above the ceiling: not covered
            ->and($a['warnings'])->toContain('Wage rate Rs 30,000.00 a month is below the minimum wage Rs 40,000.00.');

        $this->a->forceFill(['eobi_applicable' => false])->save();
        expect(prLine(app(WageEngine::class)->calculate($this->a->fresh(), '2026-09'), 'deductions', 'EOBI'))->toBe(0);
    });

    it('withholds income tax on salary by the slabs, spread over the rest of the tax year', function () {
        expect(IncomeTaxStep::taxOn(500_000_00, app(PayrollSettings::class)->get('income_tax_slabs', now())))->toBe(0)
            ->and(IncomeTaxStep::taxOn(1_800_000_00, app(PayrollSettings::class)->get('income_tax_slabs', now())))->toBe(72_000_00);

        prPunchMonth($this->b, '2026-09');
        $pay = app(WageEngine::class)->calculate($this->b->fresh(), '2026-09');
        // September is month 3 of the July–June year; nothing paid by this payroll before:
        // 150,000 now + 9 × 150,000 = 1,500,000 → tax 6,000 + 11 % × 300,000 = 39,000 over 10 months.
        expect(prLine($pay, 'deductions', 'TAX'))->toBe(3_900_00)
            ->and(prLine(app(WageEngine::class)->calculate($this->a->fresh(), '2026-09'), 'deductions', 'TAX'))->toBe(0); // below 600,000 a year
    });
});

describe('payroll run', function () {
    it('locks attendance, calculates, needs a different approver, finalizes after the month and posts the books', function () {
        prPunchMonth($this->a, '2026-09', ['2026-09-07']);
        prPunchMonth($this->b, '2026-09');
        $this->f->as($this->f->hr);
        $adv = app(AdvanceService::class)->request($this->a, 'advance', 10_000_00, 4_000_00, CarbonImmutable::now('Asia/Karachi')->format('Y-m'), 'Medical');
        expect(fn () => $this->svc->open(CarbonImmutable::now()->addMonths(2)->format('Y-m')))->toThrow(BusinessRuleViolation::class, 'not started');

        $run = $this->svc->open('2026-09');
        expect($run->number)->toContain('/PR/')->and(fn () => $this->svc->open('2026-09'))->toThrow(BusinessRuleViolation::class, 'already a payroll');
        $run = $this->svc->lock($run);
        expect($run->status)->toBe('locked')->and($run->lock_no)->toBe(1);

        // The locked month's attendance can no longer change.
        $leave = LeaveType::query()->where('code', 'CL')->sole();
        expect(fn () => app(ApprovalService::class)->submit(ManualAttendance::KEY, ['employee_id' => $this->a->id, 'kind' => 'in', 'at' => '2026-09-07 09:00', 'reason' => 'Forgot the phone'], 'Manual', 'x'))
            ->toThrow(ValidationException::class);

        $run = $this->svc->calculate($run);
        $run = $this->svc->calculate($run);
        expect($run->calculation_no)->toBe(2)->and(PayrollItem::query()->count())->toBe(4)   // two sets kept
            ->and($run->totals['employees'])->toBe(2)
            ->and(fn () => $this->svc->approve($run))->toThrow(BusinessRuleViolation::class, 'hr.payroll.approve');

        $this->f->as($this->f->owner);
        $run = $this->svc->approve($run, 'Checked against the register');
        $this->f->as($this->f->hr);
        expect(fn () => app(PayAdjustmentService::class)->request($this->a, '2026-09', 'bonus', 'PERF', 'Bonus', 100_00, 'Too late now'))->toThrow(BusinessRuleViolation::class, 'already approved');

        $this->f->as($this->f->accountant);
        $run = $this->svc->finalize($run);
        $items = $run->currentItems()->get()->keyBy('employee_id');
        $a = $items[$this->a->id];
        expect($run->status)->toBe('finalized')
            ->and($a->line('earnings', 'BASIC'))->toBe(29_000_00)           // one day absent
            ->and(Payslip::query()->count())->toBe(2)
            ->and(Payslip::query()->where('employee_id', $this->a->id)->value('number'))->toContain('/PSL/');

        $lines = VoucherLine::query()->where('voucher_id', $run->voucher_id)->with('account')->get()
            ->mapWithKeys(fn ($l) => [$l->account->code => [$l->dr_paise, $l->cr_paise]]);
        $gross = $items->sum('gross_paise');
        $employer = $items->sum('employer_paise');
        expect($lines['EXP-SALARY'])->toBe([$gross, 0])
            ->and($lines['SAL-PAYABLE'])->toBe([0, $items->sum('net_paise')])
            ->and($lines['WHT-149'][1])->toBe($items->sum(fn ($i) => $i->line('deductions', 'TAX')))
            ->and($lines['EOBI-PAYABLE'][1])->toBe($items->sum(fn ($i) => $i->line('deductions', 'EOBI') + $i->line('employer', 'EOBI_ER')))
            ->and($lines->sum(fn ($l) => $l[0]))->toBe($gross + $employer)
            ->and($lines->sum(fn ($l) => $l[1]))->toBe($gross + $employer);
        expect($adv->fresh()->status)->toBe('requested') // not paid out: nothing recovered
            ->and(fn () => $this->svc->calculate($run))->toThrow(BusinessRuleViolation::class);
        // Last: a failed statement ends the PostgreSQL transaction.
        expect(fn () => DB::table('payroll_items')->where('id', $a->id)->update(['net_paise' => 1]))->toThrow(Exception::class);
    });

    it('recovers a disbursed advance at finalization, and can unlock before approval to take a correction', function () {
        prPunchMonth($this->a, '2026-09');
        $this->f->as($this->f->hr);
        // Advance whose recovery starts in September (requested back then; the service only accepts current months, so set directly).
        $adv = app(AdvanceService::class)->request($this->a, 'advance', 10_000_00, 4_000_00, CarbonImmutable::now('Asia/Karachi')->format('Y-m'), 'Medical');
        $this->f->as($this->f->accountant);
        app(AdvanceService::class)->approve($adv, null);
        $this->f->as($this->f->hr);
        app(AdvanceService::class)->disburse($adv->fresh(), $this->cash, CarbonImmutable::parse('2026-09-02'));
        DB::table('advances')->where('id', $adv->id)->update(['start_month' => '2026-09']);

        $run = $this->svc->lock($this->svc->open('2026-09'));
        $run = $this->svc->calculate($run);
        $run = $this->svc->unlock($run, 'Gate PC punches of the 30th were missing');
        expect($run->status)->toBe('draft');
        $run = $this->svc->calculate($this->svc->lock($run));
        expect($run->lock_no)->toBe(2);

        $this->f->as($this->f->accountant);
        $run = $this->svc->finalize($this->svc->approve($run));
        expect($adv->fresh()->balancePaise())->toBe(6_000_00)
            ->and(app(Books::class)->balance(app(ChartOfAccounts::class)->system('staff_advances')))->toBe(6_000_00);
    });
});

describe('paying salaries', function () {
    beforeEach(function () {
        prPunchMonth($this->a, '2026-09');
        prPunchMonth($this->b, '2026-09');
        $this->a->forceFill(['bank_account_number' => PakistanIds::ibanFor('HABB', '0000001234567890'), 'bank_name' => 'HBL'])->save();
        $this->f->as($this->f->hr);
        $run = $this->svc->calculate($this->svc->lock($this->svc->open('2026-09')));
        $this->f->as($this->f->accountant);
        $this->run = $this->svc->finalize($this->svc->approve($run));
        $this->items = $this->run->currentItems()->get()->keyBy('employee_id');
        $this->bank = LedgerAccount::query()->create(['entity_id' => $this->f->entity->id, 'group_id' => app(ChartOfAccounts::class)->group('BANK_ACCOUNTS')->id,
            'code' => 'BANK-MZN', 'name' => 'Meezan current', 'kind' => 'bank', 'bank_account_number' => 'PK36SCBL0000001123456702']);
        $this->pay = app(SalaryPaymentService::class);
    });

    it('makes one bank file for staff with an IBAN, posts it and refuses an edited file', function () {
        $result = $this->pay->bankFile($this->run, $this->bank, 'ibft', CarbonImmutable::parse('2026-10-01'));
        $file = $result['file'];
        expect($file->payment_count)->toBe(1)->and($result['without_iban'])->toBe([$this->b->code])
            ->and($this->pay->status($this->items[$this->a->id]->fresh()))->toBe('paid');

        $rows = array_map('str_getcsv', explode("\n", trim($this->pay->csv($file))));
        expect($rows[1])->toMatchArray([2 => PakistanIds::ibanFor('HABB', '0000001234567890'), 3 => 'HABB', 4 => number_format($this->items[$this->a->id]->net_paise / 100, 2, '.', ''), 5 => 'SALA']);
        expect(fn () => $this->pay->bankFile($this->run, $this->bank, 'ibft', CarbonImmutable::parse('2026-10-01'))['file'])->not->toThrow(Exception::class)
            ->and(app(Books::class)->balance(app(ChartOfAccounts::class)->system('salaries_payable')))->toBe(-$this->items[$this->b->id]->net_paise);

        DB::table('payroll_bank_files')->where('id', $file->id)->update(['sha256' => str_repeat('a', 64)]);
        expect(fn () => $this->pay->csv($file->fresh()))->toThrow(BusinessRuleViolation::class, 'no longer matches');
    });

    it('pays cash only within the s. 21(m) limit, never twice, and keeps the signature photo', function () {
        expect(fn () => $this->pay->payCash($this->items[$this->b->id], $this->cash, CarbonImmutable::parse('2026-10-01')))->toThrow(BusinessRuleViolation::class, '21(m)');
        $this->f->as($this->f->owner);
        app(PayrollSettings::class)->set('cash_salary_limit_paise', 40_000_00, CarbonImmutable::parse('2026-01-01'), 'Test: higher limit');
        $this->f->as($this->f->accountant);
        $payment = $this->pay->payCash($this->items[$this->a->id], $this->cash, CarbonImmutable::parse('2026-10-01'));
        expect($payment->amount_paise)->toBe($this->items[$this->a->id]->net_paise)
            ->and(fn () => $this->pay->payCash($this->items[$this->a->id], $this->cash, CarbonImmutable::parse('2026-10-01')))->toThrow(BusinessRuleViolation::class, 'paid in full');

        $this->f->as($this->f->hr);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $ack = $this->pay->acknowledge($payment, 'thumb', $png);
        expect($ack->method)->toBe('thumb')
            ->and(fn () => $this->pay->acknowledge($payment, 'thumb', $png))->toThrow(BusinessRuleViolation::class, 'already acknowledged');
        $this->f->as($this->f->accountant);
        $this->pay->payCheque($this->items[$this->b->id], $this->bank, '000123', CarbonImmutable::parse('2026-10-02'));
        expect(app(Books::class)->balance(app(ChartOfAccounts::class)->system('salaries_payable')))->toBe(0);
    });

    it('builds the statutory returns from the finalized payroll, with full CNICs only for those allowed', function () {
        $reports = app(StatutoryReports::class);
        $this->f->as($this->f->hr);   // hr.employee.sensitive.view
        $eobi = $reports->build('eobi', '2026-09');
        expect($eobi['rows'])->toHaveCount(2)
            ->and($eobi['rows'][0][3])->toBe(PakistanIds::formatCnic(Employee::query()->find($this->a->id)->cnic))
            ->and($eobi['rows'][0][4])->toBe(370.0)
            ->and($reports->build('tax149', '2026-09')['rows'][1][4])->toBe(3900.0);
        $this->f->as($this->f->accountant);
        expect($reports->build('eobi', '2026-09')['rows'][0][3])->toStartWith('XXXXX-XXXX')
            ->and($reports->build('wages', '2026-09')['rows'])->toHaveCount(2)
            ->and(fn () => $reports->build('wages', '2026-08'))->toThrow(BusinessRuleViolation::class, 'not finalized');
        $xlsx = XlsxWriter::build('Wages', $reports->build('wages', '2026-09')['header'], $reports->build('wages', '2026-09')['rows']);
        expect(substr($xlsx, 0, 2))->toBe('PK');
    });
});

describe('screens', function () {
    function prSignIn(object $t, LoginAccount $a): void
    {
        app('auth')->forgetGuards();
        $t->flushSession();
        $t->actingAs($a, 'web')->withSession(Make::webSession($a));
        $t->f->as($a);
    }

    it('opens the payroll screens for the right roles only', function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $paths = ['/admin/hr/payroll', '/admin/hr/pay-adjustments', '/admin/hr/allowance-rules', '/admin/hr/statutory-reports'];
        $seen = [];
        foreach (['HR_MANAGER', 'ACCOUNTANT', 'SUPERVISOR', 'EMPLOYEE'] as $role) {
            prSignIn($this, Make::account($this->f->entity, $role, ['mfa' => true]));
            $seen[$role] = implode('/', array_map(fn ($p) => $this->get($p)->status(), $paths));
        }
        expect($seen)->toBe(['HR_MANAGER' => '200/200/200/200', 'ACCOUNTANT' => '200/200/200/200', 'SUPERVISOR' => '403/403/403/403', 'EMPLOYEE' => '403/403/403/403']);
    });

    it('sets allowance rules only as an approver and stores rupees and percentages exactly', function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        prSignIn($this, $this->f->hr);
        Livewire::test(ManageAllowanceRules::class)->assertActionHidden('create');
        prSignIn($this, $this->f->accountant);
        Livewire::test(ManageAllowanceRules::class)
            ->callAction('create', ['code' => 'CONV', 'name' => 'Conveyance', 'method' => 'fixed', 'amount_rupees' => '2500.50', 'prorate' => true,
                'effective_from' => '2026-07-01', 'status' => 'active'])->assertHasNoActionErrors();
        Livewire::test(ManageAllowanceRules::class)
            ->callAction('create', ['code' => 'MED', 'name' => 'Medical', 'method' => 'pct_basic', 'rate_percent' => '12.5',
                'effective_from' => '2026-07-01', 'status' => 'active'])->assertHasNoActionErrors();
        expect(AllowanceRule::query()->where('code', 'CONV')->sole()->only(['amount_paise', 'rate_bp', 'departments']))->toBe(['amount_paise' => 2_500_50, 'rate_bp' => 0, 'departments' => null])
            ->and(AllowanceRule::query()->where('code', 'MED')->value('rate_bp'))->toBe(1250);
    });

    it('requests a bonus and a festival batch on the screen, and a second person approves them', function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        prSignIn($this, $this->f->hr);
        Livewire::test(ListPayAdjustments::class)
            ->callAction('request', ['employee_id' => $this->a->id, 'month' => '2026-09', 'code' => 'PERF', 'type' => 'bonus', 'name' => 'Performance bonus',
                'amount' => '3000', 'reason' => 'Best loader of the month'])->assertNotified('Recorded — waiting for approval.')
            ->callAction('festival', ['month' => '2026-09', 'name' => 'Eid bonus', 'share' => '50', 'min_service' => '3', 'prorate' => false, 'reason' => 'Eid ul Adha'])
            ->assertNotified();
        $perf = PayAdjustment::query()->where('code', 'PERF')->sole();
        $festival = PayAdjustment::query()->where('code', 'FESTIVAL')->firstOrFail();
        expect($perf->amount_paise)->toBe(3_000_00)->and(PayAdjustment::query()->where('code', 'FESTIVAL')->count())->toBe(2);
        Livewire::test(ListPayAdjustments::class)->assertTableActionHidden('approve', $perf->id);

        prSignIn($this, $this->f->accountant);
        Livewire::test(ListPayAdjustments::class)
            ->callTableAction('approve', $perf->id, ['note' => null])->assertNotified('Approved.')
            ->callTableAction('approveBatch', $festival->id, ['note' => 'Eid'])->assertNotified('Batch approved.');
        expect(PayAdjustment::query()->where('status', 'approved')->count())->toBe(3);
    });

    it('runs the month from the screens: open, lock, calculate, approve, finalize, pay and report', function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        prPunchMonth($this->a, '2026-09');
        prPunchMonth($this->b, '2026-09');
        prSignIn($this, $this->f->hr);
        Livewire::test(ListPayrollRuns::class)->callAction('open', ['month' => '2026-09'])->assertHasNoActionErrors();
        $run = PayrollRun::query()->sole();
        Livewire::test(ViewPayrollRun::class, ['record' => $run->id])
            ->callAction('lock')->assertNotified('Attendance locked.')
            ->callAction('calculate')->assertNotified('Calculated — check the warnings, then send for approval.')
            ->assertActionHidden('approve');
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $run->fresh(), 'pageClass' => ViewPayrollRun::class])
            ->assertCanSeeTableRecords($run->fresh()->currentItems()->get())->assertSee($this->a->code)
            ->mountTableAction('lines', $run->fresh()->currentItems()->where('employee_id', $this->a->id)->value('id'))->assertSee('Net pay');

        prSignIn($this, $this->f->accountant);
        Livewire::test(ViewPayrollRun::class, ['record' => $run->id])
            ->callAction('approve', ['note' => 'Checked'])->assertNotified('Payroll approved.')
            ->callAction('finalize')->assertNotified('Finalized and posted to the books.');
        $run->refresh();
        expect($run->status)->toBe('finalized')->and($run->voucher_id)->not->toBeNull();

        $this->f->as($this->f->owner);
        app(PayrollSettings::class)->set('cash_salary_limit_paise', 40_000_00, CarbonImmutable::parse('2026-01-01'), 'Test: higher limit');
        $this->f->as($this->f->accountant);
        $item = $run->currentItems()->where('employee_id', $this->a->id)->sole();
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $run, 'pageClass' => ViewPayrollRun::class])
            ->callTableAction('payCash', $item->id, ['cash' => $this->cash->id, 'date' => '2026-10-01'])
            ->assertNotified('Cash payment posted — get the signature or thumb.');
        expect(app(SalaryPaymentService::class)->status($item))->toBe('paid');

        Livewire::test(StatutoryReportsPage::class, ['month' => '2026-09', 'report' => 'wages'])
            ->assertSee('Wages register')->assertSee($this->b->code)
            ->callAction('xlsx')->assertFileDownloaded('wages-2026-09.xlsx');
    });
});
