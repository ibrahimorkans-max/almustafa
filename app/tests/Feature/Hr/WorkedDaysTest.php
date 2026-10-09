<?php

declare(strict_types=1);

use App\Domain\Hr\Attendance\AttendanceCalculator;
use App\Domain\Hr\Leave\LeaveService;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\AttendanceReview;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveType;
use App\Models\Hr\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('WKD'));
    $this->e = $this->f->employee(['joining_date' => '2026-04-01']);
    $this->f->as($this->f->hr);
    $this->gen = Shift::query()->where('code', 'GEN')->sole(); // 09:00–18:00, 60 min break, Sunday off
    $this->e->forceFill(['shift_id' => $this->gen->id])->save();
});

/** A punch as stored after the phase 2/3 checks (default: valid). */
function wkPunch(object $t, string $kind, string $istTime, string $status = 'valid'): AttendanceLog
{
    $at = CarbonImmutable::parse($istTime, 'Asia/Karachi');

    return AttendanceLog::query()->create([
        'employee_id' => $t->e->id, 'kind' => $kind, 'source' => 'app', 'occurred_at' => $at->utc()->format('Y-m-d H:i:s.v'),
        'received_at' => $at->utc()->format('Y-m-d H:i:s.v'), 'time_check' => 'verified', 'client_event_id' => (string) Str::uuid(),
        'status' => $status, 'reasons' => $status === 'valid' ? [] : ['outside_geofence'], 'created_at' => now(),
    ]);
}

function wkDays(object $t, string $from, string $to): array
{
    $r = app(AttendanceCalculator::class)->period($t->e->fresh(), CarbonImmutable::parse($from, 'Asia/Karachi'), CarbonImmutable::parse($to, 'Asia/Karachi'));

    return ['days' => collect($r['days'])->keyBy('date')->all(), 'totals' => $r['totals']];
}

it('works out a realistic week: on time, late with overtime, half day, missing out-punch, leave, holiday, weekly off, absent', function () {
    // Week of Monday 7 Sep 2026.
    wkPunch($this, 'in', '2026-09-07 08:55');
    wkPunch($this, 'out', '2026-09-07 18:05');      // Mon: on time
    wkPunch($this, 'in', '2026-09-08 09:25');
    wkPunch($this, 'out', '2026-09-08 21:00');      // Tue: 25 min late, stayed till 9 pm
    wkPunch($this, 'in', '2026-09-09 09:00');
    wkPunch($this, 'out', '2026-09-09 13:00');      // Wed: half day
    wkPunch($this, 'in', '2026-09-10 09:02');       // Thu: forgot to punch out
    Holiday::query()->create(['date' => '2026-09-12', 'name' => 'Local fair', 'kind' => 'local']);
    $cl = LeaveType::query()->where('code', 'CL')->sole();
    app(LeaveService::class)->adjust($this->e, $cl, 2026, 'opening', '7', 'Opening balance');
    $req = app(LeaveService::class)->request($this->e, $cl, CarbonImmutable::parse('2026-09-11'), CarbonImmutable::parse('2026-09-11'), 'none', 'Family function');
    $this->f->as($this->f->supervisor);
    app(LeaveService::class)->approve($req, null);

    $r = wkDays($this, '2026-09-07', '2026-09-14');
    $d = $r['days'];
    expect($d['2026-09-07'])->toMatchArray(['code' => 'P', 'first_in' => '08:55', 'last_out' => '18:05', 'worked_minutes' => 490, 'late_minutes' => 0, 'overtime_minutes' => 0])
        ->and($d['2026-09-08'])->toMatchArray(['code' => 'P', 'late_minutes' => 25, 'worked_minutes' => 635, 'overtime_minutes' => 155])
        ->and($d['2026-09-09'])->toMatchArray(['code' => '½P', 'present' => 0.5, 'absent' => 0.5, 'worked_minutes' => 240, 'early_minutes' => 300])
        ->and($d['2026-09-10'])->toMatchArray(['code' => 'MO', 'present' => 0.0])
        ->and($d['2026-09-10']['notes'])->toContain('missing_out')
        ->and($d['2026-09-11'])->toMatchArray(['code' => 'L', 'leave_type' => 'CL', 'paid_leave' => 1.0])
        ->and($d['2026-09-12']['code'])->toBe('HO')
        ->and($d['2026-09-13']['code'])->toBe('WO')
        ->and($d['2026-09-14'])->toMatchArray(['code' => 'A', 'absent' => 1.0]);
    expect($r['totals'])->toMatchArray(['present' => 2.5, 'paid_leave' => 1.0, 'absent' => 1.5, 'holidays' => 1, 'weekly_offs' => 1,
        'incomplete' => 1, 'late_minutes' => 25, 'overtime_minutes' => 155]);
});

it('counts a night shift crossing midnight for the day it started', function () {
    $night = Shift::query()->create(['code' => 'NIGHT', 'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 30,
        'grace_late_minutes' => 10, 'grace_early_minutes' => 10, 'full_day_minutes' => 420, 'half_day_minutes' => 210, 'overtime_after_minutes' => 30, 'weekly_offs' => []]);
    $this->e->forceFill(['shift_id' => $night->id])->save();
    wkPunch($this, 'in', '2026-09-07 21:55');
    wkPunch($this, 'out', '2026-09-08 06:10');

    $d = wkDays($this, '2026-09-07', '2026-09-08')['days'];
    expect($d['2026-09-07'])->toMatchArray(['code' => 'P', 'first_in' => '21:55', 'last_out' => '06:10', 'worked_minutes' => 465])
        ->and($d['2026-09-08']['code'])->toBe('A');
});

it('counts only punches whose final status is valid: flagged waits, accepted on review counts, rejected never', function () {
    wkPunch($this, 'in', '2026-09-07 09:00');
    $out = wkPunch($this, 'out', '2026-09-07 18:00', 'flagged');
    expect(wkDays($this, '2026-09-07', '2026-09-07')['days']['2026-09-07']['code'])->toBe('MO');

    AttendanceReview::query()->create(['attendance_log_id' => $out->id, 'decision' => 'accept', 'reason' => 'Checked', 'reviewed_by' => $this->f->supervisor->id, 'reviewed_at' => now()]);
    expect(wkDays($this, '2026-09-07', '2026-09-07')['days']['2026-09-07']['code'])->toBe('P');

    AttendanceReview::query()->create(['attendance_log_id' => $out->id, 'decision' => 'reject', 'reason' => 'Second look', 'reviewed_by' => $this->f->supervisor->id, 'reviewed_at' => now()->addSecond()]);
    expect(wkDays($this, '2026-09-07', '2026-09-07')['days']['2026-09-07']['code'])->toBe('MO');
});

it('combines a half-day leave with a half day worked, and notes work on holidays and weekly offs', function () {
    $cl = LeaveType::query()->where('code', 'CL')->sole();
    app(LeaveService::class)->adjust($this->e, $cl, 2026, 'opening', '7', 'Opening balance');
    $req = app(LeaveService::class)->request($this->e, $cl, CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07'), 'second', 'Doctor');
    $this->f->as($this->f->supervisor);
    app(LeaveService::class)->approve($req, null);
    wkPunch($this, 'in', '2026-09-07 09:00');
    wkPunch($this, 'out', '2026-09-07 13:30');
    wkPunch($this, 'in', '2026-09-13 09:00');      // Sunday
    wkPunch($this, 'out', '2026-09-13 18:00');

    $d = wkDays($this, '2026-09-07', '2026-09-13')['days'];
    expect($d['2026-09-07'])->toMatchArray(['code' => '½P ½L', 'present' => 0.5, 'paid_leave' => 0.5, 'absent' => 0.0])
        ->and($d['2026-09-13']['code'])->toBe('P')->and($d['2026-09-13']['notes'])->toContain('worked_on_weekly_off');
});

it('leaves out days before joining and the future', function () {
    $this->e->forceFill(['joining_date' => '2026-09-09'])->save();
    $d = wkDays($this, '2026-09-08', now('Asia/Karachi')->addDays(2)->toDateString())['days'];
    expect($d['2026-09-08']['notes'])->toContain('not_employed')
        ->and(end($d)['notes'])->toContain('future')
        ->and(end($d)['code'])->toBe('—');
});
