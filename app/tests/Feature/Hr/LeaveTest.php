<?php

declare(strict_types=1);

use App\Domain\Hr\Attendance\AttendanceImport;
use App\Domain\Hr\Leave\LeaveService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\AttendanceRegisterPage;
use App\Filament\Resources\Hr\AttendanceLogResource\Pages\ListAttendanceLogs;
use App\Filament\Resources\Hr\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Resources\Hr\EmployeeResource\RelationManagers\LeaveLedgerRelationManager;
use App\Filament\Resources\Hr\LeaveRequestResource\Pages\CreateLeaveRequest;
use App\Filament\Resources\Hr\LeaveRequestResource\Pages\ListLeaveRequests;
use App\Filament\Resources\Hr\ShiftResource\Pages\ManageShifts;
use App\Models\AuditLog;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveLedgerEntry;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Models\Hr\Shift;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->f = new HrFixtures(Make::entity('LVE'));
    $this->login = Make::account($this->f->entity, 'EMPLOYEE');
    $this->e = $this->f->employee(['person_id' => $this->login->person_id, 'full_name' => null, 'joining_date' => '2026-01-01']);
    $this->f->as($this->f->hr);
    $this->e->forceFill(['shift_id' => Shift::query()->where('code', 'GEN')->value('id')])->save();
    $this->cl = LeaveType::query()->where('code', 'CL')->sole();
    $this->el = LeaveType::query()->where('code', 'EL')->sole();
    $this->lwp = LeaveType::query()->where('code', 'LWP')->sole();
    $this->svc = app(LeaveService::class);
    $this->svc->adjust($this->e, $this->cl, 2026, 'opening', '7', 'Opening 2026');
});

function lv(string $d): CarbonImmutable
{
    return CarbonImmutable::parse($d, 'Asia/Kolkata');
}

describe('leave requests and balances', function () {
    it('counts only working days, approves against the balance and records it in the ledger', function () {
        Holiday::query()->create(['date' => '2026-11-10', 'name' => 'Diwali', 'kind' => 'festival']);
        // Mon 9 – Sun 15 Nov: Tue 10 is a holiday, Sun 15 the weekly off → 5 days.
        $r = $this->svc->request($this->e, $this->cl, lv('2026-11-09'), lv('2026-11-15'), 'none', 'Diwali at home');
        expect((string) $r->days)->toBe('5.0');

        $this->f->as($this->f->supervisor);
        $this->svc->approve($r, 'Enjoy');
        expect($this->svc->balance($this->e, $this->cl, 2026))->toBe('2.0')
            ->and(LeaveLedgerEntry::query()->where('kind', 'taken')->value('days'))->toBe('-5.0');
    });

    it('refuses more paid leave than the balance, but leave without pay needs none', function () {
        $r = $this->svc->request($this->e, $this->cl, lv('2026-11-02'), lv('2026-11-13'), 'none', 'Long trip');
        $this->f->as($this->f->supervisor);
        expect(fn () => $this->svc->approve($r, null))->toThrow(BusinessRuleViolation::class, 'Only 7.0 day(s) of Casual leave left');

        $this->f->as($this->f->hr);
        $w = $this->svc->request($this->e, $this->lwp, lv('2026-12-01'), lv('2026-12-02'), 'none', 'Unpaid trip');
        $this->f->as($this->f->supervisor);
        expect($this->svc->approve($w, null)->status)->toBe('approved')
            ->and(LeaveLedgerEntry::query()->where('leave_type_id', $this->lwp->id)->count())->toBe(0);
    });

    it('refuses overlaps, half days over several days, requests across the year end, and dates that are all offs', function () {
        $this->svc->request($this->e, $this->cl, lv('2026-11-02'), lv('2026-11-03'), 'none', 'First');
        expect(fn () => $this->svc->request($this->e, $this->cl, lv('2026-11-03'), lv('2026-11-04'), 'none', 'Second'))->toThrow(BusinessRuleViolation::class, 'already a pending or approved')
            ->and(fn () => $this->svc->request($this->e, $this->cl, lv('2026-11-05'), lv('2026-11-06'), 'first', 'Half'))->toThrow(BusinessRuleViolation::class, 'single day')
            ->and(fn () => $this->svc->request($this->e, $this->cl, lv('2026-12-30'), lv('2027-01-02'), 'none', 'New year'))->toThrow(BusinessRuleViolation::class, 'Split the leave')
            ->and(fn () => $this->svc->request($this->e, $this->cl, lv('2026-11-08'), lv('2026-11-08'), 'none', 'Sunday'))->toThrow(BusinessRuleViolation::class, 'no leave is needed');
        expect((string) $this->svc->request($this->e, $this->cl, lv('2026-11-05'), lv('2026-11-05'), 'second', 'Doctor')->days)->toBe('0.5');
    });

    it('lets nobody decide their own leave, and needs hr.leave.approve', function () {
        $supE = $this->f->employee(['person_id' => $this->f->supervisor->person_id, 'full_name' => null]);
        $this->svc->adjust($supE, $this->cl, 2026, 'opening', '7', 'Opening');
        $own = $this->svc->request($supE, $this->cl, lv('2026-11-02'), lv('2026-11-02'), 'none', 'Mine');
        $this->f->as($this->f->supervisor);
        expect(fn () => $this->svc->approve($own, null))->toThrow(BusinessRuleViolation::class, 'your own leave');
        $this->f->as($this->f->gate);
        expect(fn () => $this->svc->approve($own, null))->toThrow(BusinessRuleViolation::class, 'hr.leave.approve');
    });

    it('cancels approved leave by a reversal that gives the days back', function () {
        $r = $this->svc->request($this->e, $this->cl, lv('2026-11-02'), lv('2026-11-03'), 'none', 'Wedding');
        $this->f->as($this->f->supervisor);
        $this->svc->approve($r, null);
        $this->svc->cancel($r->fresh(), 'Wedding postponed');
        expect($this->svc->balance($this->e, $this->cl, 2026))->toBe('7.0')
            ->and(LeaveLedgerEntry::query()->pluck('kind')->all())->toBe(['opening', 'taken', 'reversal'])
            ->and($r->fresh()->status)->toBe('cancelled');
        expect(fn () => DB::table('leave_ledger')->update(['days' => '99']))->toThrow(Exception::class);
    });

    it('carries leave forward at year end up to the limit, lapses the rest, once', function () {
        $this->svc->adjust($this->e, $this->el, 2025, 'opening', '40', 'Earned up to 2025');
        $this->svc->adjust($this->e, $this->cl, 2025, 'opening', '3', 'Left over');
        // EL: lapse 10 + carry out + carry in; CL: lapse 3 → 4 entries.
        expect($this->svc->yearEnd(2025))->toBe(4)->and($this->svc->yearEnd(2025))->toBe(0);
        expect($this->svc->balance($this->e, $this->el, 2025))->toBe('0.0')
            ->and($this->svc->balance($this->e, $this->el, 2026))->toBe('30.0')          // carried, limit 30
            ->and($this->svc->balance($this->e, $this->cl, 2025))->toBe('0.0')           // CL lapses
            ->and($this->svc->balance($this->e, $this->cl, 2026))->toBe('7.0');
        // The carried-in days must not make 2026 look already closed.
        expect($this->svc->yearEnd(2026))->toBe(3)
            ->and($this->svc->balance($this->e, $this->el, 2027))->toBe('30.0')
            ->and($this->svc->balance($this->e, $this->cl, 2027))->toBe('0.0');
        $this->artisan('hr:leave-year-end 2025')->assertSuccessful();
        $this->artisan('hr:leave-year-end 2099')->assertFailed();
    });
});

describe('employee self-service API', function () {
    it('shows balances, takes a request and withdraws it', function () {
        $h = Make::apiHeaders(Make::apiTokens($this->login, 'phone-leave-00000001'), 'phone-leave-00000001');
        $me = $this->withHeaders($h)->getJson('/api/v1/leave/me')->assertOk()->json();
        expect(collect($me['types'])->firstWhere('code', 'CL')['balance'])->toBe('7.0');

        $id = $this->withHeaders($h)->postJson('/api/v1/leave/requests', ['leave_type_id' => $this->cl->id, 'from' => '2026-11-02', 'to' => '2026-11-03', 'reason' => 'Fever'])
            ->assertCreated()->assertJson(['days' => '2.0', 'status' => 'pending'])->json('id');
        $this->withHeaders($h)->postJson('/api/v1/leave/requests', ['leave_type_id' => $this->cl->id, 'from' => '2026-11-03', 'to' => '2026-11-03', 'reason' => 'Again'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'There is already a pending or approved leave on these dates.']);
        $this->withHeaders($h)->postJson("/api/v1/leave/requests/{$id}/withdraw", ['reason' => 'Feeling better'])->assertOk()->assertJson(['status' => 'cancelled']);
    });
});

describe('biometric import', function () {
    it('previews, imports, infers directions, and adds nothing the second time', function () {
        $csv = "employee_code,time,direction\n{$this->e->code},2026-09-07 08:58,in\n{$this->e->code},07/09/2026 18:02,\n"
            ."{$this->e->code},2026-09-08 09:01,\n{$this->e->code},2026-09-08 17:59,\n{$this->e->code},2026-09-08 17:59,\nNOPE,2026-09-08 09:00,in\n{$this->e->code},yesterday,in\n";
        $p = app(AttendanceImport::class)->preview($csv);
        expect($p['rows'])->toHaveCount(5)->and($p['errors'])->toHaveCount(2)
            ->and(collect($p['rows'])->where('duplicate', true))->toHaveCount(1);

        $r = app(AttendanceImport::class)->import($csv);
        expect($r)->toMatchArray(['imported' => 4, 'duplicates' => 1]);
        $logs = AttendanceLog::query()->orderBy('occurred_at')->get();
        expect($logs->pluck('kind')->all())->toBe(['in', 'out', 'in', 'out'])
            ->and($logs[1]->reasons)->toBe(['imported', 'direction_inferred'])
            ->and($logs[0]->reasons)->toBe(['imported'])
            ->and($logs[0]->signals['import_file_sha256'])->toBe(hash('sha256', $csv));
        expect(app(AttendanceImport::class)->import($csv)['imported'])->toBe(0)
            ->and(AuditLog::query()->where('action', 'hr.attendance.imported')->count())->toBe(2);

        $this->f->as($this->f->gate);
        expect(fn () => app(AttendanceImport::class)->import($csv))->toThrow(BusinessRuleViolation::class, 'hr.attendance.override');
    });
});

describe('screens', function () {
    function lvSignIn(object $t, LoginAccount $a): void
    {
        $t->actingAs($a, 'web')->withSession(Make::webSession($a));
        $t->f->as($a);
    }

    it('opens leave, shifts, holidays, leave types and the register for HR, owners and supervisors only', function () {
        $paths = ['/admin/hr/leave-requests', '/admin/hr/shifts', '/admin/hr/holidays', '/admin/hr/leave-types', '/admin/hr/attendance-register'];
        $actual = [];
        foreach (['HR_MANAGER' => 200, 'OWNER' => 200, 'SUPERVISOR' => 200, 'GATE_OPERATOR' => 403, 'EMPLOYEE' => 403] as $role => $status) {
            lvSignIn($this, Make::account($this->f->entity, $role, ['mfa' => true]));
            $actual[$role] = implode('/', array_map(fn ($p) => $this->get($p)->status(), $paths));
            app('auth')->forgetGuards();
            $this->flushSession();
        }
        expect($actual)->toBe(['HR_MANAGER' => '200/200/200/200/200', 'OWNER' => '200/200/200/200/200', 'SUPERVISOR' => '200/200/200/200/200',
            'GATE_OPERATOR' => '403/403/403/403/403', 'EMPLOYEE' => '403/403/403/403/403']);
    });

    it('enters leave for an employee and the supervisor approves it from the list', function () {
        lvSignIn($this, $this->f->hr);
        Livewire::test(CreateLeaveRequest::class)->fillForm([
            'employee_id' => $this->e->id, 'leave_type_id' => $this->cl->id, 'from_date' => '2026-11-02', 'to_date' => '2026-11-02', 'half_day' => 'none', 'reason' => 'Bank work',
        ])->call('create')->assertHasNoFormErrors();
        $r = LeaveRequest::query()->sole();
        app('auth')->forgetGuards();
        $this->flushSession();
        lvSignIn($this, $this->f->supervisor);
        Livewire::test(ListLeaveRequests::class)->assertCanSeeTableRecords([$r])->callTableAction('approve', $r->id, ['note' => 'ok'])->assertNotified('Leave approved.');
        expect($r->fresh()->status)->toBe('approved');
    });

    it('imports a biometric file from the attendance screen', function () {
        lvSignIn($this, $this->f->hr);
        $csv = "{$this->e->code},2026-09-07 09:00\n{$this->e->code},2026-09-07 18:00\n";
        Livewire::test(ListAttendanceLogs::class)
            ->callAction('import', ['file' => [UploadedFile::fake()->createWithContent('punches.csv', $csv)]])
            ->assertHasNoActionErrors()->assertNotified('2 punch(es) imported, 0 skipped as already imported.');
        expect(AttendanceLog::query()->pluck('kind')->all())->toBe(['in', 'out']);
    });

    it('shows the leave tab on the employee and records a balance adjustment', function () {
        lvSignIn($this, $this->f->hr);
        Livewire::test(LeaveLedgerRelationManager::class, ['ownerRecord' => $this->e, 'pageClass' => ViewEmployee::class])
            ->assertSuccessful()->assertSee('Opening 2026')
            ->callTableAction('adjust', data: ['leave_type_id' => $this->el->id, 'year' => 2026, 'kind' => 'accrual', 'days' => '1.5', 'note' => 'Earned Sept'])
            ->assertNotified('Balance recorded.');
        expect($this->svc->balance($this->e, $this->el, 2026))->toBe('1.5');
    });

    it('creates a night shift', function () {
        lvSignIn($this, $this->f->hr);
        Livewire::test(ManageShifts::class)->callAction('create', [
            'code' => 'NIGHT', 'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 30, 'grace_late_minutes' => 10,
            'grace_early_minutes' => 10, 'full_day_minutes' => 420, 'half_day_minutes' => 210, 'overtime_after_minutes' => 30, 'weekly_offs' => [],
        ])->assertHasNoActionErrors();
        expect(Shift::query()->where('code', 'NIGHT')->sole()->crossesMidnight())->toBeTrue();
    });

    it('shows the attendance register with day codes and exports it', function () {
        lvSignIn($this, $this->f->hr);
        Livewire::test(AttendanceRegisterPage::class)->set('month', '2026-09')->assertSee($this->e->code)->assertSee('WO')
            ->callAction('csv')->assertFileDownloaded('attendance-register-2026-09.csv');
        expect(AuditLog::query()->where('action', 'report.exported')->latest('occurred_at')->first()->meta['report'])->toBe('attendance_register');
    });
});
