<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Hr\Attendance\AttendanceService;
use App\Domain\Hr\Attendance\Geo;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\EmployeeDevice;
use App\Models\Hr\Site;
use App\Models\StoredFile;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

// Mill gate at Taraori (Karnal): the test geofence.
const MILL_LAT = 29.7972;
const MILL_LNG = 76.9921;
const PHONE = 'phone-ramesh-000000001';

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('ATT'));
    $this->login = Make::account($this->f->entity, 'EMPLOYEE');
    $this->employee = $this->f->employee(['person_id' => $this->login->person_id, 'full_name' => null]);
    $this->f->as($this->f->hr);
    Site::query()->create(['code' => 'MILL', 'name' => 'Main mill', 'kind' => 'mill', 'latitude' => MILL_LAT, 'longitude' => MILL_LNG,
        'radius_m' => 150, 'time_windows' => [['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '05:00', 'to' => '23:00']]]);
    Make::reset();
    $this->headers = Make::apiHeaders(Make::apiTokens($this->login, PHONE), PHONE);
    $this->seq = 0;
    $this->boot = 'boot-'.Str::random(8);
});

/** Accept the notice and get the phone approved by HR, as on day one. */
function onboard(object $t): void
{
    $notice = $t->withHeaders($t->headers)->getJson('/api/v1/attendance/me')->assertOk()->json('notice');
    $t->withHeaders($t->headers)->postJson('/api/v1/attendance/consent', ['notice_version' => $notice['version']])->assertCreated();
    $t->withHeaders($t->headers)->postJson('/api/v1/attendance/device')->assertCreated()->assertJson(['status' => 'pending']);
    app(AuthContextBinder::class)->bind($t->f->hr);
    $binding = EmployeeDevice::query()->where('employee_id', $t->employee->id)->sole();
    app(AttendanceService::class)->decideDevice($binding, true, 'Checked the phone in person');
    Make::reset();
}

/** @param  array<string, mixed>  $overrides */
function punchEvent(object $t, string $kind, array $overrides = [], ?CarbonInterface $at = null, int $monotonic = 50_000_000): array
{
    $at ??= now();

    return [
        'event_id' => (string) Str::uuid(), 'client_seq' => ++$t->seq, 'occurred_at' => $at->toIso8601ZuluString('millisecond'),
        'type' => 'attendance.punch', 'record_id' => null, 'base_version' => null,
        'payload' => $overrides + [
            'kind' => $kind, 'device_time' => $at->toIso8601ZuluString('millisecond'), 'lat' => MILL_LAT + 0.0003, 'lng' => MILL_LNG,
            'accuracy_m' => 12, 'boot_id' => $t->boot, 'monotonic_ms' => $monotonic, 'source' => 'pwa',
            'selfie_sha256' => $t->selfie ?? null,
        ],
    ];
}

/** @param  array<string, mixed>|null  $clock */
function pushPunches(object $t, array $events, ?array $clock = null): array
{
    $clock ??= ['boot_id' => $t->boot, 'monotonic_ms' => 50_000_500, 'wall_time' => now()->toIso8601ZuluString()];

    return $t->withHeaders($t->headers)->postJson('/api/v1/sync/push', ['events' => $events, 'device_clock' => $clock])->assertOk()->json('results');
}

function uploadSelfie(object $t): string
{
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
    // As the PWA sends it: a browser form upload of the camera frame.
    $res = $t->withHeaders($t->headers)->post('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('selfie.jpg', $jpeg)])->assertCreated();

    return $t->selfie = (string) $res->json('sha256');
}

it('measures distances on the ground correctly', function () {
    expect(Geo::distanceMeters(MILL_LAT, MILL_LNG, MILL_LAT + 0.001, MILL_LNG))->toBeBetween(110, 112)
        ->and(Geo::distanceMeters(28.6139, 77.2090, 19.0760, 72.8777))->toBeBetween(1_148_000, 1_155_000); // Delhi–Mumbai
});

it('tells the employee honestly what is collected and needs consent before a punch counts', function () {
    $me = $this->withHeaders($this->headers)->getJson('/api/v1/attendance/me')->assertOk()->json();
    expect($me['employee']['code'])->toBe($this->employee->code)
        ->and($me['notice']['text'])->toContain('only to confirm your attendance')->toContain('not tracked')
        ->and($me['notice']['accepted'])->toBeFalse()
        ->and($me['sites'][0]['radius_m'])->toBe(150);

    $result = pushPunches($this, [punchEvent($this, 'in')])[0];
    expect($result['status'])->toBe('applied')->and($result['attendance_status'])->toBe('rejected')
        ->and($result['reasons'])->toContain('no_consent')
        ->and($result['messages'])->toContain('The attendance notice has not been accepted');
});

it('accepts a punch at the mill from the approved phone with a photo as valid', function () {
    onboard($this);
    uploadSelfie($this);
    $r = pushPunches($this, [punchEvent($this, 'in')])[0];

    expect($r['attendance_status'])->toBe('valid')->and($r['reasons'])->toBe([]);
    $log = Make::system(fn () => AttendanceLog::query()->sole());
    expect($log->time_check)->toBe('verified')->and($log->distance_m)->toBeLessThan(40)
        ->and($log->site_id)->not->toBeNull()->and($log->selfie_sha256)->toBe($this->selfie)
        ->and($log->device_id)->toBe(Make::system(fn () => Device::query()->where('device_uid', PHONE)->value('id')));

    $today = $this->withHeaders($this->headers)->getJson('/api/v1/attendance/me')->json('today');
    expect($today)->toHaveCount(1)->and($today[0]['status'])->toBe('valid');
});

it('flags, never silently accepts, punches from outside the site, an unapproved phone, poor GPS or without a photo', function () {
    $notice = $this->withHeaders($this->headers)->getJson('/api/v1/attendance/me')->json('notice');
    $this->withHeaders($this->headers)->postJson('/api/v1/attendance/consent', ['notice_version' => $notice['version']]);

    $r = pushPunches($this, [punchEvent($this, 'in', ['lat' => MILL_LAT + 0.05, 'accuracy_m' => 400])])[0];
    expect($r['attendance_status'])->toBe('flagged')
        ->and($r['reasons'])->toContain('outside_geofence', 'device_not_approved', 'low_accuracy', 'no_selfie');
});

it('dates an offline punch with the monotonic clock and rejects a changed phone clock', function () {
    onboard($this);
    uploadSelfie($this);
    // Taken 3 h ago while offline (monotonic 3 h before the upload's reading), phone clock honest.
    $honest = punchEvent($this, 'in', [], now()->subHours(3), 50_000_500 - 3 * 3_600_000);
    // Taken 1 h ago, but the phone clock had been set back 2 h to fake an early arrival.
    $faked = punchEvent($this, 'out', [], now()->subHours(3), 50_000_500 - 3_600_000);
    [$a, $b] = pushPunches($this, [$honest, $faked]);

    expect($a['attendance_status'])->toBe('valid')
        ->and($b['attendance_status'])->toBe('rejected')->and($b['reasons'])->toContain('device_clock_changed')
        ->and($b['messages'])->toContain('The phone clock was changed between the punch and the upload');
    $faked = Make::system(fn () => AttendanceLog::query()->where('kind', 'out')->sole());
    expect($faked->estimated_at->diffInMinutes(now()->subHour(), true))->toBeLessThan(2.0);
});

it('flags a punch whose time cannot be checked (app restarted before the upload)', function () {
    onboard($this);
    uploadSelfie($this);
    $r = pushPunches($this, [punchEvent($this, 'in')], ['boot_id' => 'another-boot', 'monotonic_ms' => 10, 'wall_time' => now()->toIso8601ZuluString()])[0];
    expect($r['attendance_status'])->toBe('flagged')->and($r['reasons'])->toBe(['time_unverified']);
});

it('keeps punches older than the offline limit as flagged evidence instead of dropping them in the sync queue', function () {
    onboard($this);
    uploadSelfie($this);
    $r = pushPunches($this, [punchEvent($this, 'in', [], now()->subDays(4))], ['boot_id' => 'x', 'monotonic_ms' => 1, 'wall_time' => now()->toIso8601ZuluString()])[0];
    expect($r['status'])->toBe('applied')->and($r['reasons'])->toContain('late_upload')
        ->and(Make::system(fn () => AttendanceLog::query()->count()))->toBe(1);
});

it('rejects a future-dated punch even though the sync envelope clamps the time', function () {
    onboard($this);
    uploadSelfie($this);
    $r = pushPunches($this, [punchEvent($this, 'in', [], now()->addHours(2))])[0];
    expect($r['attendance_status'])->toBe('rejected')->and($r['reasons'])->toContain('future_time');
});

it('is idempotent: the same offline punch sent twice is stored once', function () {
    onboard($this);
    uploadSelfie($this);
    $e = punchEvent($this, 'in');
    pushPunches($this, [$e]);
    $again = pushPunches($this, [$e])[0];
    expect($again['duplicate'] ?? false)->toBeTrue()->and(Make::system(fn () => AttendanceLog::query()->count()))->toBe(1);
});

it('waits for a photo that has not been uploaded yet instead of losing it', function () {
    onboard($this);
    $r = pushPunches($this, [punchEvent($this, 'in', ['selfie_sha256' => str_repeat('a', 64)])])[0];
    expect($r['status'])->toBe('retry')->and($r['reason'])->toBe('selfie_not_uploaded')
        ->and(Make::system(fn () => AttendanceLog::query()->count()))->toBe(0);
});

it('flags two ins in a row and punches outside the site hours', function () {
    onboard($this);
    uploadSelfie($this);
    app(AuthContextBinder::class)->bind($this->f->hr);
    Site::query()->first()->update(['time_windows' => [['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '23:58', 'to' => '23:59']]]);
    Make::reset();
    [$a, $b] = pushPunches($this, [punchEvent($this, 'in', [], now()->subMinutes(5), 50_000_500 - 300_000), punchEvent($this, 'in')]);
    expect($a['reasons'])->toContain('outside_time_window')->and($b['reasons'])->toContain('sequence');
});

it('approves one phone per employee: a new phone replaces the old one, and nobody approves their own', function () {
    onboard($this);
    $headers2 = Make::apiHeaders(Make::apiTokens($this->login, 'phone-ramesh-000000002'), 'phone-ramesh-000000002');
    $this->withHeaders($headers2)->postJson('/api/v1/attendance/device')->assertCreated();

    $svc = app(AttendanceService::class);
    $new = Make::system(fn () => EmployeeDevice::query()->where('status', 'pending')->sole());
    app(AuthContextBinder::class)->bind($this->login);
    expect(fn () => $svc->decideDevice($new, true, null))->toThrow(BusinessRuleViolation::class, 'hr.employee.edit');
    app(AuthContextBinder::class)->bind($this->f->hr);
    $svc->decideDevice($new, true, 'New phone after the old one broke');

    expect(EmployeeDevice::query()->where('status', 'approved')->count())->toBe(1)
        ->and(EmployeeDevice::query()->where('status', 'revoked')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'hr.attendance.device_approved')->count())->toBe(2);
});

it('refuses punches from logins without an employee record or without the permission', function () {
    $stranger = Make::account($this->f->entity, 'EMPLOYEE');
    $this->headers = Make::apiHeaders(Make::apiTokens($stranger, 'phone-stranger-00001'), 'phone-stranger-00001');
    $r = pushPunches($this, [punchEvent($this, 'in')])[0];
    expect($r['status'])->toBe('rejected')->and($r['data']['message'] ?? $r['message'] ?? '')->toContain('not linked to an employee');

    $broker = Make::account($this->f->entity, 'BROKER');
    $this->headers = Make::apiHeaders(Make::apiTokens($broker, 'phone-broker-000001'), 'phone-broker-000001');
    $this->withHeaders($this->headers)->getJson('/api/v1/attendance/me')->assertForbidden();
    expect(pushPunches($this, [punchEvent($this, 'in')])[0]['reason'])->toBe('forbidden');
});

it('keeps punches and consents append-only at the database', function () {
    onboard($this);
    uploadSelfie($this);
    pushPunches($this, [punchEvent($this, 'in')]);
    $log = Make::system(fn () => AttendanceLog::query()->sole());
    expect(fn () => $log->forceFill(['status' => 'rejected'])->save())->toThrow(LogicException::class);
    expect(fn () => Make::system(fn () => DB::table('attendance_logs')->update(['status' => 'valid'])))->toThrow(Exception::class);
});

it('grants punching to every staff role but not to brokers, and creates the Employee role', function () {
    foreach (['EMPLOYEE', 'GATE_OPERATOR', 'SUPERVISOR', 'ACCOUNTANT', 'HR_MANAGER', 'OWNER'] as $role) {
        expect(Make::account($this->f->entity, $role, ['mfa' => true])->hasPermission('hr.attendance.punch'))->toBeTrue($role);
    }
    expect(Make::account($this->f->entity, 'BROKER')->hasPermission('hr.attendance.punch'))->toBeFalse()
        ->and(Make::system(fn () => StoredFile::query()->count()))->toBe(0);
});
