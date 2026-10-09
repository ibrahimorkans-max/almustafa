<?php

declare(strict_types=1);

use App\Domain\Approvals\Actions\ManualAttendance;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Hr\Attendance\AttendanceReviewService;
use App\Domain\Hr\Attendance\AttendanceService;
use App\Domain\Hr\Attendance\DeviceClock;
use App\Domain\Hr\Attendance\IntegrityVerifier;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\AttendanceLogResource\Pages\ListAttendanceLogs;
use App\Filament\Resources\Hr\AttendanceLogResource\Pages\ViewAttendanceLog;
use App\Models\Alert;
use App\Models\ApprovalRequest;
use App\Models\Device;
use App\Models\Hr\AttendanceConsent;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\AttendanceReview;
use App\Models\Hr\EmployeeDevice;
use App\Models\Hr\Site;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

const INT_LAT = 29.7972;
const INT_LNG = 76.9921;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->f = new HrFixtures(Make::entity('INT'));
    $this->login = Make::account($this->f->entity, 'EMPLOYEE');
    $this->employee = $this->f->employee(['person_id' => $this->login->person_id, 'full_name' => null]);
    $this->f->as($this->f->hr);
    Site::query()->create(['code' => 'MILL', 'name' => 'Main mill', 'kind' => 'mill', 'latitude' => INT_LAT, 'longitude' => INT_LNG, 'radius_m' => 300]);
    AttendanceConsent::query()->create(['employee_id' => $this->employee->id, 'login_account_id' => $this->login->id,
        'notice_version' => (string) config('ricemill.attendance.notice_version'), 'accepted_at' => now()]);
    config(['ricemill.attendance.require_selfie' => false]);
    // The employee's approved phone, so a clean punch is valid.
    $this->phone = Device::query()->create(['login_account_id' => $this->login->id, 'device_uid' => 'int-phone-00000001', 'platform' => 'android', 'status' => 'active']);
    EmployeeDevice::query()->create(['employee_id' => $this->employee->id, 'device_id' => $this->phone->id, 'requested_at' => now()])
        ->forceFill(['status' => EmployeeDevice::APPROVED])->save();
    Make::reset();
});

/** Consent for another employee, recorded in their entity. */
function intConsent(LoginAccount $login, string $employeeId): void
{
    app(AuthContextBinder::class)->bind($login);
    AttendanceConsent::query()->create(['employee_id' => $employeeId, 'login_account_id' => $login->id,
        'notice_version' => (string) config('ricemill.attendance.notice_version'), 'accepted_at' => now()]);
}

/**
 * One punch through the service, as the sync handler does (same boot so the time is verified).
 *
 * @param  array<string, mixed>  $p
 */
function intPunch(object $t, array $p = [], ?CarbonImmutable $at = null, ?LoginAccount $as = null): AttendanceLog
{
    $at ??= CarbonImmutable::now();
    $clock = app(DeviceClock::class);
    $clock->set(['boot_id' => 'boot-1', 'monotonic_ms' => 90_000_000, 'wall_time' => now()->toIso8601ZuluString()]);
    app(AuthContextBinder::class)->bind($as ?? $t->login);

    return app(AttendanceService::class)->punch($as ?? $t->login, $as === null ? $t->phone : null, (string) Str::uuid(), $at, $p + [
        'kind' => 'in', 'lat' => INT_LAT + random_int(1, 900) / 1_000_000, 'lng' => INT_LNG + random_int(1, 900) / 1_000_000,
        'accuracy_m' => 9, 'boot_id' => 'boot-1', 'monotonic_ms' => 90_000_000 - (int) round(now()->diffInMilliseconds($at, true)),
    ], 'app');
}

function intSignIn(object $t, LoginAccount $a): void
{
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

describe('phone integrity signals', function () {
    it('rejects a location the phone itself reports as fake, and keeps the evidence', function () {
        $log = intPunch($this, ['signals' => ['platform' => 'android', 'mock_location' => true]]);
        expect($log->status)->toBe('rejected')->and($log->reasons)->toContain('mock_location')
            ->and($log->reasonTexts())->toContain('The phone reported a fake (mock / simulated) location')
            ->and($log->signals['mock_location'])->toBeTrue();
    });

    it('flags a rooted phone, hooking tools, an emulator and an enabled fake-location app', function () {
        $log = intPunch($this, ['signals' => ['platform' => 'android', 'rooted' => true, 'hooking' => true, 'emulator' => true, 'mock_apps_enabled' => true]]);
        expect($log->status)->toBe('flagged')->and($log->reasons)->toContain('rooted_device', 'hooking_detected', 'emulator', 'mock_apps_enabled');
        $ios = intPunch($this, ['kind' => 'out', 'signals' => ['platform' => 'ios', 'jailbroken' => 'true']], CarbonImmutable::now()->addMinute());
        expect($ios->reasons)->toContain('rooted_device');
    });

    it('never reports an integrity token as passed when nobody verified it', function () {
        config(['ricemill.attendance.require_integrity' => true]);
        $log = intPunch($this, ['signals' => ['platform' => 'android', 'integrity_token' => 'eyJ…']]);
        expect($log->reasons)->toContain('integrity_unverified')->and($log->signals['integrity_verdict'])->toBe('unverified');
    });

    it('uses a configured verifier: a failed Play Integrity verdict is flagged, a pass is clean', function () {
        app()->instance(IntegrityVerifier::class, new class implements IntegrityVerifier
        {
            public function verify(string $platform, string $token): string
            {
                return $token === 'good' ? self::PASSED : self::FAILED;
            }
        });
        expect(intPunch($this, ['signals' => ['platform' => 'android', 'integrity_token' => 'bad']])->reasons)->toContain('integrity_failed')
            ->and(intPunch($this, ['kind' => 'out', 'signals' => ['platform' => 'android', 'integrity_token' => 'good']], CarbonImmutable::now()->addMinute())->status)->toBe('valid');
    });

    it('flags punches without integrity information only when the native app is required', function () {
        expect(intPunch($this)->status)->toBe('valid');
        config(['ricemill.attendance.require_integrity' => true]);
        expect(intPunch($this, ['kind' => 'out'], CarbonImmutable::now()->addMinute())->reasons)->toContain('integrity_unknown');
    });
});

describe('server-side sanity checks', function () {
    it('flags impossible travel between two punches (Karnal to Delhi in 20 minutes)', function () {
        intPunch($this, [], CarbonImmutable::now()->subMinutes(20));
        $log = intPunch($this, ['kind' => 'out', 'lat' => 28.6139, 'lng' => 77.2090]);
        expect($log->reasons)->toContain('impossible_travel')->and($log->reasons)->toContain('outside_geofence');
    });

    it('does not mistake normal GPS jitter or a real day trip for impossible travel', function () {
        intPunch($this, [], CarbonImmutable::now()->subHours(8));
        // Same mill, 8 hours later.
        expect(intPunch($this, ['kind' => 'out'])->reasons)->not->toContain('impossible_travel');
    });

    it('flags exactly repeated coordinates (real GPS always varies) and unrealistically perfect accuracy', function () {
        intPunch($this, ['lat' => 29.7973111, 'lng' => 76.9922111], CarbonImmutable::now()->subDay());
        $log = intPunch($this, ['kind' => 'out', 'lat' => 29.7973111, 'lng' => 76.9922111, 'accuracy_m' => 0]);
        expect($log->reasons)->toContain('repeated_coordinates', 'suspicious_accuracy');
    });

    it('flags one phone punching for two employees', function () {
        $phone = $this->phone;
        $other = Make::account($this->f->entity, 'EMPLOYEE');
        $otherEmployee = $this->f->employee(['person_id' => $other->person_id, 'full_name' => null]);
        intConsent($other, $otherEmployee->id);

        app(AuthContextBinder::class)->bind($this->login);
        app(AttendanceService::class)->punch($this->login, $phone, (string) Str::uuid(), CarbonImmutable::now()->subHour(), ['kind' => 'in', 'lat' => INT_LAT, 'lng' => INT_LNG + 0.0001, 'accuracy_m' => 8], 'app');
        app(AuthContextBinder::class)->bind($other);
        $log = app(AttendanceService::class)->punch($other, $phone, (string) Str::uuid(), CarbonImmutable::now(), ['kind' => 'in', 'lat' => INT_LAT + 0.0002, 'lng' => INT_LNG, 'accuracy_m' => 8], 'app');
        expect($log->reasons)->toContain('shared_device');
    });

    it('flags a phone clock that is off from the server', function () {
        $clock = app(DeviceClock::class);
        app(AuthContextBinder::class)->bind($this->login);
        $clock->set(['boot_id' => 'b2', 'monotonic_ms' => 1_000_000, 'wall_time' => now()->subMinutes(20)->toIso8601ZuluString()]);
        $log = app(AttendanceService::class)->punch($this->login, null, (string) Str::uuid(), CarbonImmutable::now(), ['kind' => 'in', 'lat' => INT_LAT, 'lng' => INT_LNG + 0.0003, 'accuracy_m' => 7, 'boot_id' => 'b2', 'monotonic_ms' => 1_000_000], 'app');
        expect($log->reasons)->toContain('device_clock_off');
    });
});

describe('review queue', function () {
    it('accepts or rejects a flagged punch with a reason; the punch itself never changes; the latest decision counts', function () {
        $log = intPunch($this, ['lat' => 29.9, 'lng' => 77.1]); // outside the site → flagged
        $svc = app(AttendanceReviewService::class);

        app(AuthContextBinder::class)->bind($this->f->gate);
        expect(fn () => $svc->decide($log, 'accept', 'Looks fine to me'))->toThrow(BusinessRuleViolation::class, 'hr.attendance.review');
        app(AuthContextBinder::class)->bind($this->f->supervisor);
        expect(fn () => $svc->decide($log, 'accept', 'ok'))->toThrow(BusinessRuleViolation::class, 'reason');

        $svc->decide($log, 'accept', 'Was at the procurement point in Nilokheri on my instruction');
        expect($log->fresh()->finalStatus())->toBe('valid')->and($log->fresh()->status)->toBe('flagged');
        $svc->decide($log, 'reject', 'Second look: the photo is of another person');
        expect($log->fresh()->finalStatus())->toBe('rejected')->and(AttendanceReview::query()->count())->toBe(2);

        expect(fn () => DB::table('attendance_reviews')->update(['decision' => 'accept']))->toThrow(Exception::class);
    });

    it('only reviews flagged punches and never your own', function () {
        $valid = intPunch($this);
        app(AuthContextBinder::class)->bind($this->f->supervisor);
        expect(fn () => app(AttendanceReviewService::class)->decide($valid, 'reject', 'Just because'))->toThrow(BusinessRuleViolation::class, 'nothing to review');

        $supEmployee = $this->f->employee(['person_id' => $this->f->supervisor->person_id, 'full_name' => null]);
        intConsent($this->f->supervisor, $supEmployee->id);
        $own = intPunch($this, ['lat' => 29.9, 'lng' => 77.1], null, $this->f->supervisor);
        app(AuthContextBinder::class)->bind($this->f->supervisor);
        expect(fn () => app(AttendanceReviewService::class)->decide($own, 'accept', 'I was there, honestly'))->toThrow(BusinessRuleViolation::class, 'your own');
    });

    it('lists what awaits review and decides it from the punch page', function () {
        $flagged = intPunch($this, ['lat' => 29.9, 'lng' => 77.1]);
        $valid = intPunch($this, ['kind' => 'out'], CarbonImmutable::now()->addMinute());
        intSignIn($this, $this->f->supervisor);
        Livewire::test(ListAttendanceLogs::class)->filterTable('awaiting', true)
            ->assertCanSeeTableRecords([$flagged])->assertCanNotSeeTableRecords([$valid])->assertSee('awaiting review');
        Livewire::test(ViewAttendanceLog::class, ['record' => $flagged->id])
            ->callAction('accept', ['reason' => 'Called him: he was loading at godown 3'])->assertNotified('Punch accepted.');
        Livewire::test(ListAttendanceLogs::class)->filterTable('awaiting', true)->assertCanNotSeeTableRecords([$flagged]);
        Livewire::test(ViewAttendanceLog::class, ['record' => $flagged->id])->assertSee('Called him: he was loading at godown 3')->assertActionVisible('reject');
    });
});

describe('manual attendance', function () {
    it('is created only after a different person approves, as a manual punch linked to the request', function () {
        app(AuthContextBinder::class)->bind($this->f->hr);
        $req = app(ApprovalService::class)->submit(ManualAttendance::KEY, [
            'employee_id' => $this->employee->id, 'kind' => 'in', 'at' => now('Asia/Kolkata')->subHours(3)->format('Y-m-d H:i'),
            'reason' => 'Phone battery dead at the Nilokheri buying point',
        ], 'Manual attendance', 'battery');
        expect(AttendanceLog::query()->count())->toBe(0)
            ->and(app(ApprovalService::class)->decisionBlocker($this->f->hr, $req))->toContain('cannot approve your own request');

        app(AuthContextBinder::class)->bind($this->f->owner);
        $done = app(ApprovalService::class)->approve($req, $this->f->owner, 'Confirmed with the buying-point register');
        $log = AttendanceLog::query()->sole();
        expect($done->status)->toBe(ApprovalRequest::STATUS_EXECUTED)
            ->and($log->source)->toBe('manual')->and($log->approval_request_id)->toBe($req->id)
            ->and($log->reasonTexts())->toBe(['Entered manually (approved by a second person)'])
            ->and($log->occurred_at->timezone('Asia/Kolkata')->format('H:i'))->toBe(now('Asia/Kolkata')->subHours(3)->format('H:i'));
    });

    it('refuses future times, own attendance and missing reasons', function () {
        app(AuthContextBinder::class)->bind($this->f->hr);
        $svc = app(ApprovalService::class);
        $base = ['employee_id' => $this->employee->id, 'kind' => 'in', 'reason' => 'Forgot the phone at home'];
        expect(fn () => $svc->submit(ManualAttendance::KEY, ['at' => now('Asia/Kolkata')->addHour()->format('Y-m-d H:i')] + $base, 'x'))->toThrow(ValidationException::class)
            ->and(fn () => $svc->submit(ManualAttendance::KEY, ['at' => now('Asia/Kolkata')->subHour()->format('Y-m-d H:i'), 'reason' => 'x'] + $base, 'x'))->toThrow(ValidationException::class);
        $hrEmployee = $this->f->employee(['person_id' => $this->f->hr->person_id, 'full_name' => null]);
        app(AuthContextBinder::class)->bind($this->f->hr);
        expect(fn () => $svc->submit(ManualAttendance::KEY, ['employee_id' => $hrEmployee->id, 'at' => now('Asia/Kolkata')->subHour()->format('Y-m-d H:i')] + $base, 'x'))
            ->toThrow(ValidationException::class);
    });

    it('is requested from the attendance screen', function () {
        intSignIn($this, $this->f->hr);
        Livewire::test(ListAttendanceLogs::class)->callAction('manual', [
            'employee_id' => $this->employee->id, 'kind' => 'out', 'at' => now('Asia/Kolkata')->subHour()->format('Y-m-d H:i'), 'reason' => 'No network at the godown',
        ])->assertNotified('Sent for approval.');
        expect(ApprovalRequest::query()->where('action', ManualAttendance::KEY)->count())->toBe(1);
    });
});

it('alerts reviewers when one employee repeatedly sends fake locations, once per week', function () {
    $svc = app(AttendanceReviewService::class);
    foreach ([0, 1, 2] as $i) {
        intPunch($this, ['kind' => $i % 2 ? 'out' : 'in', 'signals' => ['platform' => 'android', 'mock_location' => true]], CarbonImmutable::now()->subMinutes(30 - $i));
    }
    app(AuthContextBinder::class)->bind($this->f->hr);
    expect($svc->alertOnRepeats($this->employee))->not->toBeNull()
        ->and($svc->alertOnRepeats($this->employee))->toBeNull()
        ->and(Alert::query()->where('type', 'attendance_tampering')->sole()->title)->toContain('Repeated fake-location')
        ->and(DB::table('notifications')->where('notifiable_id', $this->f->supervisor->id)->count())->toBe(1);
});
