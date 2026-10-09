<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Auth\LoginService;
use App\Domain\Security\Lockdown;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\SecurityCentre;
use App\Jobs\Middleware\PauseDuringLockdown;
use App\Models\AuditLog;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->e1 = Make::entity('LK1');
    $this->e2 = Make::entity('LK2');
    $this->owner1 = Make::account($this->e1, 'OWNER');
    $this->owner2 = Make::account($this->e2, 'OWNER');
    $this->gate1 = Make::account($this->e1, 'GATE_OPERATOR');
    $this->gate2 = Make::account($this->e2, 'GATE_OPERATOR');
    $this->sa = Make::superAdmin($this->e1);
});

function lockAs(LoginAccount $sa, ?string $entityId, string $reason = 'Suspected break-in at the weighbridge PC'): object
{
    app(AuthContextBinder::class)->bind($sa);

    return app(Lockdown::class)->lock($entityId, $reason, Make::totp($sa));
}

function loginFails(LoginAccount $a): ?AuthenticationFailed
{
    try {
        app(LoginService::class)->verifyPassword($a->username, Make::PASSWORD, '127.0.0.1');
    } catch (AuthenticationFailed $e) {
        return $e;
    }

    return null;
}

it('needs a Super Admin, the current 2FA code and a reason', function () {
    app(AuthContextBinder::class)->bind($this->owner1);
    expect(fn () => app(Lockdown::class)->lock(null, 'Owner tries', '123456'))->toThrow(BusinessRuleViolation::class, 'Only a Super Admin');

    app(AuthContextBinder::class)->bind($this->sa);
    expect(fn () => app(Lockdown::class)->lock(null, 'Wrong code used', '000000'))->toThrow(BusinessRuleViolation::class, 'code is not valid');
    expect(fn () => app(Lockdown::class)->lock(null, 'x', Make::totp($this->sa)))->toThrow(BusinessRuleViolation::class, 'reason');
    expect(Make::system(fn () => AuditLog::query()->where('action', 'security.lockdown.reauth_failed')->count()))->toBe(1)
        ->and(app(Lockdown::class)->activeLocks())->toBe([]);
});

it('locks everything: sessions and tokens end, only Super Admins get in, owners are told', function () {
    $web = Make::webSession($this->gate1);
    $pair = Make::apiTokens($this->gate2);

    lockAs($this->sa, null);
    Make::reset();

    $this->getJson('/api/v1/auth/me', Make::apiHeaders($pair))->assertUnauthorized()
        ->assertJson(['reason' => 'system_locked', 'message' => 'System locked by administrator.']);
    $this->actingAs($this->gate1, 'web')->withSession($web)->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertSee('System locked by administrator.');

    foreach ([$this->owner1, $this->gate2] as $a) {
        $e = loginFails($a);
        expect($e?->publicMessage())->toBe('System locked by administrator.')->and($e?->status)->toBe(423);
    }
    expect(loginFails($this->sa))->toBeNull();

    expect(DB::table('notifications')->whereIn('notifiable_id', [$this->owner1->id, $this->owner2->id])->count())->toBe(2)
        ->and(Make::system(fn () => AuditLog::query()->where('action', 'security.lockdown.started')->first()->meta['sessions_ended']))->toBeGreaterThanOrEqual(2);
});

it('locks one entity without touching the others, and unlocking is a separate audited step', function () {
    $lock = lockAs($this->sa, $this->e1->id, 'Accountant laptop stolen');

    expect(loginFails($this->gate1)?->publicCode)->toBe('system_locked')
        ->and(loginFails($this->gate2))->toBeNull()
        ->and(DB::table('notifications')->where('notifiable_id', $this->owner2->id)->count())->toBe(0);

    app(AuthContextBinder::class)->bind($this->sa);
    expect(fn () => app(Lockdown::class)->unlock($lock->id, 'Safe now', '000000'))->toThrow(BusinessRuleViolation::class, 'code is not valid');
    // TOTP codes are single-use: the code of the next 30-second window (accepted with one step of drift).
    $g = app(Google2FA::class);
    $next = $g->oathTotp((string) $this->sa->mfa_secret, $g->getTimestamp() + 1);
    app(Lockdown::class)->unlock($lock->id, 'Laptop recovered, passwords changed', $next);

    expect(loginFails($this->gate1))->toBeNull()
        ->and(Make::system(fn () => AuditLog::query()->where('action', 'security.lockdown.ended')->exists()))->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $this->owner1->id)->count())->toBe(2);
});

it('pauses queued work and scheduled tasks for the locked scope', function () {
    lockAs($this->sa, $this->e1->id);

    $job = new class
    {
        public ?int $released = null;

        public bool $ran = false;

        public function release(int $delay): void
        {
            $this->released = $delay;
        }
    };
    (new PauseDuringLockdown($this->e1->id))->handle($job, function () use ($job) {
        $job->ran = true;
    });
    expect($job->ran)->toBeFalse()->and($job->released)->toBe(300);
    (new PauseDuringLockdown($this->e2->id))->handle($job, function () use ($job) {
        $job->ran = true;
    });
    expect($job->ran)->toBeTrue();

    $this->artisan('anomalies:scan')->expectsOutputToContain('LK1: skipped, locked by administrator')->assertSuccessful();
});

it('keeps the Security centre away from everyone but Super Admins', function () {
    $this->actingAs($this->owner1, 'web')->withSession(Make::webSession($this->owner1))->get('/admin/security-centre')->assertForbidden();
});

it('locks from the Security centre', function () {
    $this->actingAs($this->sa, 'web')->withSession(Make::webSession($this->sa));
    app(AuthContextBinder::class)->bind($this->sa);
    $this->get('/admin/security-centre')->assertOk()->assertSee('No lockdown is active.');
    Livewire::test(SecurityCentre::class)
        ->callAction('lockdown', ['scope' => $this->e2->id, 'reason' => 'Drill for the runbook', 'code' => Make::totp($this->sa)])
        ->assertNotified('Lockdown started. Everyone in scope has been signed out.');
    expect(app(Lockdown::class)->isLocked($this->e2->id))->toBeTrue()->and(app(Lockdown::class)->isLocked($this->e1->id))->toBeFalse();
    Livewire::test(SecurityCentre::class)->assertSee('Drill for the runbook')->assertActionVisible('unlock');
});
