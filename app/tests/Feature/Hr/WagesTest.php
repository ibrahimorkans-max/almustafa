<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Hr\Payroll\AdvanceService;
use App\Domain\Hr\Payroll\PayrollSettings;
use App\Domain\Hr\Payroll\PieceWorkService;
use App\Domain\Hr\Payroll\WageEngine;
use App\Domain\Hr\SalaryStructureService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\PayrollSettingsPage;
use App\Filament\Resources\Hr\AdvanceResource\Pages\CreateAdvance;
use App\Filament\Resources\Hr\AdvanceResource\Pages\ListAdvances;
use App\Filament\Resources\Hr\PieceWorkEntryResource\Pages\ListPieceWorkEntries;
use App\Models\Accounts\VoucherLine;
use App\Models\Hr\Advance;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\PieceWorkEntry;
use App\Models\Hr\Shift;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('WAG'));
    $this->e = $this->f->employee(['joining_date' => '2026-01-01']);
    $this->f->as($this->f->hr);
    // Statutory deductions (EOBI, income tax) are tested in StatutoryPayrollTest; here only the wage rules.
    $this->e->forceFill(['shift_id' => Shift::query()->where('code', 'GEN')->value('id'), 'eobi_applicable' => false, 'pessi_applicable' => false])->save(); // 09:00–18:00, 1 h break, Sunday off
    $this->engine = app(WageEngine::class);
});

/** @param  array<string, int>  $amounts rupees */
function wgSalary(object $t, string $from, string $basis, array $amounts, array $units = []): void
{
    $t->f->as($t->f->hr);
    app(SalaryStructureService::class)->create($t->e, CarbonImmutable::parse($from), $basis, array_map(fn ($r) => $r * 100, $amounts), 'Test salary', $units);
}

/**
 * A month of attendance rows as the calculator produces them: every working day present, Sundays
 * weekly off; $changes override single days.
 *
 * @param  array<string, array<string, mixed>>  $changes
 */
function wgMonth(string $month, array $changes = [], ?string $joined = null): array
{
    $start = CarbonImmutable::parse($month.'-01');
    $days = [];
    for ($d = $start; $d->month === $start->month; $d = $d->addDay()) {
        $key = $d->toDateString();
        $off = $d->isSunday();
        $row = ['date' => $key, 'code' => $off ? 'WO' : 'P', 'present' => $off ? 0.0 : 1.0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0,
            'absent' => 0.0, 'leave_type' => null, 'worked_minutes' => $off ? 0 : 480, 'late_minutes' => 0, 'early_minutes' => 0,
            'overtime_minutes' => 0, 'notes' => []];
        if ($joined !== null && $key < $joined) {
            $row = array_merge($row, ['code' => '—', 'present' => 0.0, 'worked_minutes' => 0, 'notes' => ['not_employed']]);
        }
        $days[] = array_merge($row, $changes[$key] ?? []);
    }

    return ['days' => $days, 'totals' => ['incomplete' => count(array_filter($days, fn ($r) => $r['code'] === 'MO'))]];
}

function wgLine(array $pay, string $section, string $code): ?int
{
    $line = collect($pay[$section])->firstWhere('code', $code);

    return $line['amount_paise'] ?? null;
}

describe('monthly salary', function () {
    it('prorates every pay head by paid days over the calendar days, with absence and unpaid leave as loss of pay', function () {
        wgSalary($this, '2026-01-01', 'monthly', ['BASIC' => 30000, 'HRA' => 6000]);
        $pay = $this->engine->calculate($this->e, '2026-09', wgMonth('2026-09', [
            '2026-09-07' => ['code' => 'A', 'present' => 0.0, 'absent' => 1.0],
            '2026-09-08' => ['code' => 'L', 'present' => 0.0, 'unpaid_leave' => 1.0, 'leave_type' => 'LWP'],
            '2026-09-09' => ['code' => '½P', 'present' => 0.5, 'absent' => 0.5],
        ]));

        // September: 30 days; 2.5 days lost → 27.5 paid (Sundays and holidays are paid to monthly staff).
        expect(wgLine($pay, 'earnings', 'BASIC'))->toBe(27_500_00)
            ->and(wgLine($pay, 'earnings', 'HRA'))->toBe(5_500_00)
            ->and($pay['info'])->toMatchArray(['paid_days' => '27.5', 'lop_days' => '2.5', 'calendar_days' => 30])
            ->and($pay['net_paise'])->toBe(33_000_00)
            ->and(collect($pay['earnings'])->firstWhere('code', 'BASIC')['basis'])->toBe('27.5 paid days of 30 (monthly rate Rs 30,000.00)');
    });

    it('applies a mid-month increment day by day and pays a new joiner from the joining date', function () {
        wgSalary($this, '2026-01-01', 'monthly', ['BASIC' => 30000]);
        wgSalary($this, '2026-09-16', 'monthly', ['BASIC' => 33000]);
        expect(wgLine($this->engine->calculate($this->e, '2026-09', wgMonth('2026-09')), 'earnings', 'BASIC'))
            ->toBe(31_500_00); // 15 × 1,000 + 15 × 1,100

        $this->e->forceFill(['joining_date' => '2026-09-11'])->save();
        $pay = $this->engine->calculate($this->e->fresh(), '2026-10', wgMonth('2026-10'));
        expect($pay['info']['employed_days'])->toBe(31);
        $sep = $this->engine->calculate($this->e->fresh(), '2026-09', wgMonth('2026-09', [], '2026-09-11'));
        expect(wgLine($sep, 'earnings', 'BASIC'))->toBe(21_500_00) // 11th–15th: 5 × 1,000; 16th–30th: 15 × 1,100 (joined on the 11th)
            ->and($sep['info'])->toMatchArray(['employed_days' => 20, 'paid_days' => '20']);
    });

    it('does not pay days with a missing punch until they are corrected, and says so', function () {
        wgSalary($this, '2026-01-01', 'monthly', ['BASIC' => 30000]);
        $pay = $this->engine->calculate($this->e, '2026-09', wgMonth('2026-09', ['2026-09-10' => ['code' => 'MO', 'present' => 0.0, 'notes' => ['missing_out']]]));

        expect(wgLine($pay, 'earnings', 'BASIC'))->toBe(29_000_00)
            ->and($pay['warnings'])->toContain('1 day(s) with a missing punch are unpaid until corrected.');
    });

    it('pays overtime at twice the hourly rate in 30-minute blocks', function () {
        wgSalary($this, '2026-01-01', 'monthly', ['BASIC' => 20800]); // Rs 100 an hour (÷ 208 h)
        $pay = $this->engine->calculate($this->e, '2026-09', wgMonth('2026-09', [
            '2026-09-01' => ['overtime_minutes' => 95],   // → 90 minutes
            '2026-09-02' => ['overtime_minutes' => 29],   // → nothing
        ]));

        expect(wgLine($pay, 'earnings', 'OT'))->toBe(300_00) // 1.5 h × 100 × 2
            ->and($pay['info']['overtime_minutes'])->toBe(90);
    });

    it('adds one more day rate for a weekly off worked, and follows a changed multiplier only from its date', function () {
        wgSalary($this, '2026-01-01', 'monthly', ['BASIC' => 30000]);
        $sunday = ['2026-09-13' => ['code' => 'P', 'present' => 1.0, 'notes' => ['worked_on_weekly_off']]];
        expect(wgLine($this->engine->calculate($this->e, '2026-09', wgMonth('2026-09', $sunday)), 'earnings', 'HOLIDAY_WORK'))->toBe(1_000_00);

        $this->f->as($this->f->accountant);
        app(PayrollSettings::class)->set('holiday_work_multiplier_bp', 25000, CarbonImmutable::parse('2026-10-01'), 'Mill policy from October');
        expect(wgLine($this->engine->calculate($this->e, '2026-09', wgMonth('2026-09', $sunday)), 'earnings', 'HOLIDAY_WORK'))->toBe(1_000_00)
            ->and(wgLine($this->engine->calculate($this->e, '2026-10', wgMonth('2026-10', ['2026-10-11' => $sunday['2026-09-13']])), 'earnings', 'HOLIDAY_WORK'))
            ->toBe(1_451_61); // from October 2.5 × in total: 30,000 ÷ 31 days × 1.5 extra = 1,451.61
        expect(fn () => app(PayrollSettings::class)->set('ot_multiplier_bp', '2x', CarbonImmutable::parse('2026-10-01'), 'Typo'))->toThrow(BusinessRuleViolation::class);
        $this->f->as($this->f->hr);
        expect(fn () => app(PayrollSettings::class)->set('ot_multiplier_bp', 15000, CarbonImmutable::parse('2026-10-01'), 'Not allowed'))->toThrow(BusinessRuleViolation::class, 'hr.payroll.approve');
    });
});

describe('daily wages', function () {
    it('pays days present and paid leave at the day rate; holidays only by setting; twice for a holiday worked', function () {
        wgSalary($this, '2026-01-01', 'daily', ['BASIC' => 1500]);
        $month = wgMonth('2026-09', [
            '2026-09-07' => ['code' => 'A', 'present' => 0.0, 'absent' => 1.0],
            '2026-09-08' => ['code' => '½P', 'present' => 0.5, 'absent' => 0.5],
            '2026-09-09' => ['code' => 'L', 'present' => 0.0, 'paid_leave' => 1.0, 'leave_type' => 'CL'],
            '2026-09-10' => ['code' => 'HO', 'present' => 0.0],
            '2026-09-11' => ['code' => 'P', 'present' => 1.0, 'notes' => ['worked_on_holiday']],
        ]);
        $pay = $this->engine->calculate($this->e, '2026-09', $month);

        // 26 working days: 22 present (including the 11th, worked on a holiday) + 0.5 + 1 paid leave = 23.5; the 10th (holiday) unpaid.
        expect(wgLine($pay, 'earnings', 'BASIC'))->toBe(35_250_00)
            ->and(wgLine($pay, 'earnings', 'HOLIDAY_WORK'))->toBe(1_500_00)
            ->and($pay['info']['paid_days'])->toBe('23.5');

        $this->f->as($this->f->accountant);
        app(PayrollSettings::class)->set('daily_paid_holidays', true, CarbonImmutable::parse('2026-09-01'), 'Mill pays holidays to daily staff');
        expect(wgLine($this->engine->calculate($this->e, '2026-09', $month), 'earnings', 'BASIC'))->toBe(36_750_00); // + the 10th
    });

    it('pays daily overtime at twice the day rate per shift hour', function () {
        wgSalary($this, '2026-01-01', 'daily', ['BASIC' => 1400]); // shift 8 h → Rs 175 an hour
        $pay = $this->engine->calculate($this->e, '2026-09', wgMonth('2026-09', ['2026-09-01' => ['overtime_minutes' => 60]]));

        expect(wgLine($pay, 'earnings', 'OT'))->toBe(350_00);
    });

    it('works from real punches as well', function () {
        wgSalary($this, '2026-01-01', 'daily', ['BASIC' => 1500]);
        foreach (['2026-09-01', '2026-09-02'] as $day) {
            foreach ([['in', '08:55'], ['out', '18:05']] as [$kind, $time]) {
                $at = CarbonImmutable::parse("{$day} {$time}", 'Asia/Karachi')->utc()->format('Y-m-d H:i:s.v');
                AttendanceLog::query()->create(['employee_id' => $this->e->id, 'kind' => $kind, 'source' => 'app', 'occurred_at' => $at, 'received_at' => $at,
                    'time_check' => 'verified', 'client_event_id' => (string) Str::uuid(), 'status' => 'valid', 'reasons' => [], 'created_at' => now()]);
            }
        }
        $pay = $this->engine->calculate($this->e, '2026-09');

        expect(wgLine($pay, 'earnings', 'BASIC'))->toBe(3_000_00)
            ->and($pay['attendance']['present'])->toBe(2.0);
    });
});

describe('piece rate', function () {
    it('splits a gang\'s bags equally, counts a source once, prices each unit and reports units without a rate', function () {
        $b = $this->f->employee(['joining_date' => '2026-01-01']);
        $c = $this->f->employee(['joining_date' => '2026-01-01']);
        wgSalary($this, '2026-01-01', 'piece_rate', ['BASIC' => 5], ['BASIC' => 'bag']); // Rs 5 a bag

        $this->f->as($this->f->supervisor);
        $svc = app(PieceWorkService::class);
        $gateId = (string) Str::uuid();
        $entries = $svc->record([$this->e->id, $b->id, $c->id], CarbonImmutable::parse('2026-09-05'), 'bag', '1000', 'gate_entry', $gateId, 'Loaded truck');
        expect(array_map(fn ($e) => (string) $e->quantity, $entries))->toBe(['333.334', '333.333', '333.333'])
            ->and(fn () => $svc->record([$this->e->id], CarbonImmutable::parse('2026-09-05'), 'bag', '1000', 'gate_entry', $gateId))
            ->toThrow(BusinessRuleViolation::class, 'already been recorded');
        $svc->record([$this->e->id], CarbonImmutable::parse('2026-09-06'), 'bag', '100');
        $svc->correct(PieceWorkEntry::query()->where('quantity', '100.000')->sole(), 'Counted twice by mistake');
        $svc->record([$this->e->id], CarbonImmutable::parse('2026-09-07'), 'maund', '12.5');

        $pay = $this->engine->calculate($this->e, '2026-09', wgMonth('2026-09'));
        expect(wgLine($pay, 'earnings', 'BASIC'))->toBe(1_666_67) // 333.334 bags × Rs 5
            ->and($pay['warnings'])->toContain('12.500 maund of piece work have no rate in the salary structure and are not paid.');

        $this->f->as($this->f->gate);
        expect(fn () => $svc->record([$this->e->id], CarbonImmutable::parse('2026-09-08'), 'bag', '10'))->toThrow(BusinessRuleViolation::class, 'hr.payroll.run');
        // Last: a failed statement ends the PostgreSQL transaction.
        expect(fn () => DB::table('piece_work_entries')->update(['quantity' => 1]))->toThrow(Exception::class);
    });
});

describe('advances and loans', function () {
    beforeEach(function () {
        $this->cash = app(ChartOfAccounts::class)->system('cash');
        $this->svc = app(AdvanceService::class);
    });

    it('needs a second person to approve, posts the disbursement, recovers instalments in payroll and never more than the pay', function () {
        wgSalary($this, '2026-01-01', 'monthly', ['BASIC' => 30000]);
        $start = CarbonImmutable::now('Asia/Karachi')->format('Y-m');
        $this->f->as($this->f->hr);
        $adv = $this->svc->request($this->e, 'loan', 50_000_00, 20_000_00, $start, 'Daughter\'s wedding');
        $big = $this->svc->request($this->e, 'advance', 40_000_00, 40_000_00, $start, 'House repair');
        expect($adv->number)->toContain('/ADV/')
            ->and(fn () => $this->svc->approve($adv, null))->toThrow(BusinessRuleViolation::class, 'hr.payroll.approve');

        $this->f->as($this->f->accountant);
        $this->svc->approve($adv, 'Two months\' salary at most');
        $this->svc->approve($big, null);
        $this->f->as($this->f->hr);
        $adv = $this->svc->disburse($adv->fresh(), $this->cash, CarbonImmutable::now('Asia/Karachi'));
        $lines = VoucherLine::query()->where('voucher_id', $adv->voucher_id)->with('account')->orderBy('line_no')->get()->map(fn ($l) => [$l->account->code, $l->dr_paise, $l->cr_paise])->all();
        expect($adv->status)->toBe('disbursed')
            ->and($lines)->toBe([['STAFF-ADV', 50_000_00, 0], ['CASH', 0, 50_000_00]])
            ->and(app(Books::class)->balance(app(ChartOfAccounts::class)->system('staff_advances')))->toBe(50_000_00);

        $pay = $this->engine->calculate($this->e, $start, wgMonth($start));
        expect($pay['gross_paise'])->toBe(30_000_00)
            ->and(collect($pay['deductions'])->where('code', 'ADV')->pluck('amount_paise')->all())->toBe([20_000_00])
            ->and($pay['net_paise'])->toBe(10_000_00);

        // The second advance's instalment does not fit: only what is left of the pay is taken, the rest stays on the balance.
        $this->svc->disburse($big->fresh(), $this->cash, CarbonImmutable::now('Asia/Karachi'));
        $pay = $this->engine->calculate($this->e, $start, wgMonth($start));
        expect(collect($pay['deductions'])->where('code', 'ADV')->pluck('amount_paise')->all())->toBe([20_000_00, 10_000_00])
            ->and($pay['net_paise'])->toBe(0)
            ->and(implode(' ', $pay['warnings']))->toContain('stays on the balance');
    });

    it('takes cash repayments, keeps the schedule and closes at zero', function () {
        $start = CarbonImmutable::now('Asia/Karachi')->format('Y-m');
        $adv = $this->svc->request($this->e, 'advance', 10_000_00, 5_000_00, $start, 'Medical treatment');
        $this->f->as($this->f->accountant);
        $this->svc->approve($adv, null);
        $this->f->as($this->f->hr);
        $adv = $this->svc->disburse($adv->fresh(), $this->cash, CarbonImmutable::now('Asia/Karachi'));

        $this->svc->repay($adv, 4_000_00, $this->cash, CarbonImmutable::now('Asia/Karachi'), 'Paid back at the office');
        expect($adv->fresh()->balancePaise())->toBe(6_000_00)
            ->and($this->svc->schedule($adv->fresh())[0])->toMatchArray(['month' => $start, 'planned_paise' => 5_000_00, 'recovered_paise' => 4_000_00, 'balance_after_paise' => 6_000_00])
            ->and(fn () => $this->svc->repay($adv->fresh(), 7_000_00, $this->cash, CarbonImmutable::now('Asia/Karachi'), 'Too much'))->toThrow(BusinessRuleViolation::class, 'not more than the balance');

        $this->svc->repay($adv->fresh(), 6_000_00, $this->cash, CarbonImmutable::now('Asia/Karachi'), 'Rest of it');
        expect($adv->fresh()->status)->toBe(Advance::CLOSED)
            ->and(app(Books::class)->balance(app(ChartOfAccounts::class)->system('staff_advances')))->toBe(0);
    });

    it('refuses self-approval, advances to oneself and past start months', function () {
        $this->f->as($this->f->hr);
        expect(fn () => $this->svc->request($this->e, 'advance', 1_000_00, 500_00, '2020-01', 'Old month'))->toThrow(BusinessRuleViolation::class, 'start this month')
            ->and(fn () => $this->svc->request($this->e, 'advance', 1_000_00, 2_000_00, CarbonImmutable::now()->format('Y-m'), 'Instalment too big'))->toThrow(BusinessRuleViolation::class, 'instalment');

        $mine = $this->f->employee(['person_id' => $this->f->accountant->person_id, 'full_name' => null]);
        $adv = $this->svc->request($mine, 'advance', 1_000_00, 500_00, CarbonImmutable::now('Asia/Karachi')->format('Y-m'), 'For myself');
        $this->f->as($this->f->accountant);
        expect(fn () => $this->svc->approve($adv, null))->toThrow(BusinessRuleViolation::class, 'your own advance');
    });
});

describe('screens', function () {
    function wgSignIn(object $t, LoginAccount $a): void
    {
        $t->actingAs($a, 'web')->withSession(Make::webSession($a));
        $t->f->as($a);
    }

    it('opens advances, piece work and payroll settings for the right roles only', function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $paths = ['/admin/hr/advances', '/admin/hr/piece-work', '/admin/hr/payroll-settings'];
        $seen = [];
        foreach (['HR_MANAGER', 'ACCOUNTANT', 'SUPERVISOR', 'GATE_OPERATOR', 'EMPLOYEE'] as $role) {
            wgSignIn($this, Make::account($this->f->entity, $role, ['mfa' => true]));
            $seen[$role] = implode('/', array_map(fn ($p) => $this->get($p)->status(), $paths));
            app('auth')->forgetGuards();
            $this->flushSession();
        }
        expect($seen)->toBe(['HR_MANAGER' => '200/200/200', 'ACCOUNTANT' => '200/200/200', 'SUPERVISOR' => '403/200/403',
            'GATE_OPERATOR' => '403/403/403', 'EMPLOYEE' => '403/403/403']);
    });

    it('requests an advance on the screen, approves it from the list and changes a rate with its source', function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        wgSignIn($this, $this->f->hr);
        Livewire::test(CreateAdvance::class)->fillForm([
            'employee_id' => $this->e->id, 'kind' => 'advance', 'amount' => '15000', 'installment' => '5000',
            'start_month' => CarbonImmutable::now('Asia/Karachi')->format('Y-m'), 'reason' => 'School fees',
        ])->call('create')->assertHasNoFormErrors();
        $adv = Advance::query()->sole();
        expect($adv->amount_paise)->toBe(15_000_00);
        app('auth')->forgetGuards();
        $this->flushSession();

        wgSignIn($this, $this->f->accountant);
        Livewire::test(ListAdvances::class)
            ->callTableAction('approve', $adv->id, ['note' => 'ok'])->assertNotified('Advance approved.');
        expect($adv->fresh()->status)->toBe('approved');

        Livewire::test(PayrollSettingsPage::class)->assertSee('default — confirm')
            ->callAction('change', ['value' => '42000', 'from' => '2026-07-01', 'note' => 'Punjab minimum wage notification'], ['key' => 'minimum_wage_monthly_paise'])
            ->assertNotified('New value recorded.');
        expect(app(PayrollSettings::class)->get('minimum_wage_monthly_paise', CarbonImmutable::parse('2026-09-01')))->toBe(42_000_00);
    });

    it('records a gang\'s piece work from the list', function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $b = $this->f->employee(['joining_date' => '2026-01-01']);
        wgSignIn($this, $this->f->supervisor);
        Livewire::test(ListPieceWorkEntries::class)
            ->callTableAction('record', data: ['employees' => [$this->e->id, $b->id], 'date' => '2026-09-05', 'unit' => 'bag', 'quantity' => '401', 'note' => 'Loading'])
            ->assertNotified('Work recorded.');
        expect(PieceWorkEntry::query()->orderByDesc('quantity')->pluck('quantity')->all())->toBe(['200.500', '200.500']);
    });
});
