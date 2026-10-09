<?php

declare(strict_types=1);

use App\Domain\Hr\Attendance\AttendanceService;
use App\Domain\Hr\EmployeeDocuments;
use App\Domain\Hr\EmployeeService;
use App\Domain\Hr\SalaryStructureService;
use App\Filament\Resources\Hr\AttendanceLogResource\Pages\ListAttendanceLogs;
use App\Filament\Resources\Hr\AttendanceLogResource\Pages\ViewAttendanceLog;
use App\Filament\Resources\Hr\EmployeeDeviceResource\Pages\ListEmployeeDevices;
use App\Filament\Resources\Hr\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Resources\Hr\EmployeeResource\RelationManagers\ClearanceItemsRelationManager;
use App\Filament\Resources\Hr\EmployeeResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Hr\EmployeeResource\RelationManagers\SalaryStructuresRelationManager;
use App\Filament\Resources\Hr\SiteResource\Pages\ManageSites;
use App\Models\Device;
use App\Models\Hr\AttendanceConsent;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\EmployeeDevice;
use App\Models\Hr\Site;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->f = new HrFixtures(Make::entity('ATA'));
    $this->login = Make::account($this->f->entity, 'EMPLOYEE');
    $this->employee = $this->f->employee(['person_id' => $this->login->person_id, 'full_name' => null]);
});

function attSignIn(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

it('opens the attendance screens for HR and owners, read-only for supervisors, not for gate operators', function () {
    $expected = ['HR_MANAGER' => 200, 'OWNER' => 200, 'SUPERVISOR' => 200, 'GATE_OPERATOR' => 403, 'EMPLOYEE' => 403];
    $actual = [];
    foreach ($expected as $role => $status) {
        attSignIn($this, Make::account($this->f->entity, $role, ['mfa' => true]));
        $actual[$role] = implode('/', array_map(fn ($p) => $this->get($p)->status(), ['/admin/hr/attendance', '/admin/hr/sites', '/admin/hr/employee-devices']));
        app('auth')->forgetGuards();
        $this->flushSession();
    }
    expect($actual)->toBe(array_map(fn ($s) => "{$s}/{$s}/{$s}", $expected));
});

it('records a site with its geofence and working hours', function () {
    attSignIn($this, $this->f->hr);
    Livewire::test(ManageSites::class)->callAction('create', [
        'code' => 'GODOWN2', 'name' => 'Godown 2', 'kind' => 'godown', 'latitude' => '29.8010000', 'longitude' => '76.9900000', 'radius_m' => 120,
        'time_windows' => [['days' => [1, 2, 3, 4, 5, 6], 'from' => '06:00', 'to' => '20:00']], 'status' => 'active',
    ])->assertHasNoActionErrors();
    $site = Site::query()->where('code', 'GODOWN2')->sole();
    expect($site->radius_m)->toBe(120)
        ->and($site->isOpenAt(CarbonImmutable::parse('2026-10-12 07:00', 'Asia/Karachi')))->toBeTrue()   // Monday
        ->and($site->isOpenAt(CarbonImmutable::parse('2026-10-11 07:00', 'Asia/Karachi')))->toBeFalse()  // Sunday
        ->and($site->isOpenAt(CarbonImmutable::parse('2026-10-12 21:00', 'Asia/Karachi')))->toBeFalse();
});

it('handles night-shift windows that cross midnight', function () {
    $site = new Site(['time_windows' => [['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '20:00', 'to' => '06:00']]]);
    expect($site->isOpenAt(CarbonImmutable::parse('2026-10-12 23:30', 'Asia/Karachi')))->toBeTrue()
        ->and($site->isOpenAt(CarbonImmutable::parse('2026-10-13 05:30', 'Asia/Karachi')))->toBeTrue()
        ->and($site->isOpenAt(CarbonImmutable::parse('2026-10-13 12:00', 'Asia/Karachi')))->toBeFalse();
});

it('approves a phone from the devices screen and lists it as approved', function () {
    Make::apiTokens($this->login, 'phone-ata-000000001');
    $this->f->as($this->login);
    $device = Make::system(fn () => Device::query()->where('device_uid', 'phone-ata-000000001')->sole());
    app(AttendanceService::class)->requestDevice($this->login, $device);

    attSignIn($this, $this->f->hr);
    $binding = EmployeeDevice::query()->sole();
    Livewire::test(ListEmployeeDevices::class)->assertCanSeeTableRecords([$binding])
        ->callTableAction('reject', $binding->id, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    Livewire::test(ListEmployeeDevices::class)->callTableAction('approve', $binding->id, ['note' => 'Seen in person'])->assertNotified('Device approved.');
    expect($binding->fresh()->status)->toBe('approved');
});

it('shows each punch with its verdict in plain words, and the evidence on the punch page', function () {
    $this->f->as($this->login);
    AttendanceConsent::query()->create(['employee_id' => $this->employee->id, 'login_account_id' => $this->login->id,
        'notice_version' => (string) config('ricemill.attendance.notice_version'), 'accepted_at' => now()]);
    app(AttendanceService::class)->punch($this->login, null, (string) Str::uuid(), CarbonImmutable::now(),
        ['kind' => 'in', 'lat' => 29.9, 'lng' => 77.1, 'accuracy_m' => 8], 'pwa');
    $log = AttendanceLog::query()->sole();

    attSignIn($this, $this->f->hr);
    Livewire::test(ListAttendanceLogs::class)->assertCanSeeTableRecords([$log])
        ->assertSee('Outside every work site')->assertSee('This phone is not approved for attendance yet');
    Livewire::test(ViewAttendanceLog::class, ['record' => $log->id])
        ->assertSee('flagged')->assertSee('29.9000000, 77.1000000')->assertSee('openstreetmap.org', false)
        ->assertSee('none sent (browser)');
});

it('filters punches by status and by PKT day', function () {
    $this->f->as($this->login);
    AttendanceConsent::query()->create(['employee_id' => $this->employee->id, 'login_account_id' => $this->login->id,
        'notice_version' => (string) config('ricemill.attendance.notice_version'), 'accepted_at' => now()]);
    $svc = app(AttendanceService::class);
    // 00:30 PKT on 6 Oct is still 5 Oct in UTC: the day filter must use PKT.
    $svc->punch($this->login, null, (string) Str::uuid(), CarbonImmutable::parse('2026-10-06 00:30', 'Asia/Karachi'), ['kind' => 'in'], 'pwa');
    $svc->punch($this->login, null, (string) Str::uuid(), CarbonImmutable::parse('2026-10-05 18:00', 'Asia/Karachi'), ['kind' => 'out'], 'pwa');
    [$late, $early] = [AttendanceLog::query()->where('kind', 'in')->sole(), AttendanceLog::query()->where('kind', 'out')->sole()];

    attSignIn($this, $this->f->hr);
    Livewire::test(ListAttendanceLogs::class)
        ->filterTable('day', ['day' => '2026-10-06'])->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$early])
        ->resetTableFilters()->filterTable('status', 'flagged')->assertCanSeeTableRecords([$late, $early])
        ->filterTable('employee_id', $this->employee->id)->assertCanSeeTableRecords([$late]);
});

it('renders the employee tabs (documents, salary history, clearance) for HR', function () {
    $e = $this->employee;
    $this->f->as($this->f->hr);
    app(SalaryStructureService::class)->create($e, CarbonImmutable::parse('2026-04-01'), 'monthly', ['BASIC' => 15_000_00, 'HRA' => 3_000_00], 'Joining');
    app(EmployeeDocuments::class)->upload($e, 'contract', 'Appointment letter', "%PDF-1.4\n%%EOF\n");
    app(EmployeeService::class)->giveNotice($e, CarbonImmutable::parse('2026-10-01'), 'Resigned');

    attSignIn($this, $this->f->hr);
    $page = ViewEmployee::class;
    Livewire::test(SalaryStructuresRelationManager::class, ['ownerRecord' => $e, 'pageClass' => $page])
        ->assertOk()->assertSee('18,000.00')->assertSee('in force');
    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $e, 'pageClass' => $page])
        ->assertOk()->assertSee('Appointment letter');
    Livewire::test(ClearanceItemsRelationManager::class, ['ownerRecord' => $e, 'pageClass' => $page])
        ->assertOk()->assertSee('ID card and keys returned');
});
