<?php

declare(strict_types=1);

use App\Domain\Auth\AccountStatusService;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Auth\LoginService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\LoginAccountResource\Pages\ListLoginAccounts;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->entity = Make::entity('ACC');
    $this->owner = Make::account($this->entity, 'SUPER_ADMIN');
    $this->user = Make::account($this->entity, 'GATE_OPERATOR');
});

function actAs(LoginAccount $account): void
{
    app(AuthContextBinder::class)->bind($account);
}

it('suspending ends web sessions and API tokens at once, and the device is told why', function () {
    $web = Make::webSession($this->user);
    $pair = Make::apiTokens($this->user);
    $this->getJson('/api/v1/auth/me', Make::apiHeaders($pair))->assertOk();

    actAs($this->owner);
    app(AccountStatusService::class)->suspend($this->user, 'Under investigation for weight slips');
    Make::reset();

    $this->getJson('/api/v1/auth/me', Make::apiHeaders($pair))->assertUnauthorized()
        ->assertJson(['reason' => 'access_disabled', 'message' => 'Access disabled, contact your administrator.']);
    $this->actingAs($this->user, 'web')->withSession($web)->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertSee('Access disabled, contact your administrator.');
    expect(Make::system(fn () => AuthSession::query()->where('login_account_id', $this->user->id)->whereNull('revoked_at')->count()))->toBe(0);
});

it('tells a suspended user plainly after the right password, stays generic otherwise, and logs every attempt', function () {
    actAs($this->owner);
    app(AccountStatusService::class)->suspend($this->user, 'Left for the season');
    Make::reset();

    try {
        app(LoginService::class)->verifyPassword($this->user->username, Make::PASSWORD, '203.0.113.7', 'device-abc');
        $this->fail('no exception');
    } catch (AuthenticationFailed $e) {
        expect($e->publicMessage())->toBe('Access disabled, contact your administrator.')->and($e->status)->toBe(403);
    }
    try {
        app(LoginService::class)->verifyPassword($this->user->username, 'wrong-password', '203.0.113.7', 'device-abc');
    } catch (AuthenticationFailed $e) {
        expect($e->publicMessage())->not->toContain('disabled');
    }

    $log = Make::system(fn () => AuditLog::query()->where('action', 'auth.login.blocked')->where('subject_id', $this->user->id)->latest('occurred_at')->first());
    expect($log->meta)->toMatchArray(['reason' => 'account_suspended', 'ip' => '203.0.113.7', 'device_uid' => 'device-abc'])
        ->and($log->meta['at'])->not->toBeEmpty();

    // The API says the same thing.
    $this->postJson('/api/v1/auth/login', ['username' => $this->user->username, 'password' => Make::PASSWORD, 'device_uid' => 'device-abc-0000001', 'platform' => 'android'])
        ->assertStatus(403)->assertJson(['error' => 'access_disabled', 'message' => 'Access disabled, contact your administrator.']);
});

it('reinstates a suspended account, but a revoked one never', function () {
    actAs($this->owner);
    $svc = app(AccountStatusService::class);
    $svc->suspend($this->user, 'Short leave');
    expect($svc->reinstate($this->user, 'Back from leave')->status)->toBe('active');
    app(LoginService::class)->verifyPassword($this->user->username, Make::PASSWORD, '127.0.0.1');

    actAs($this->owner);
    $svc->revoke($this->user, 'Left the company');
    expect(fn () => $svc->reinstate($this->user, 'Rehired'))->toThrow(BusinessRuleViolation::class, 'closed for good');
    expect(Make::system(fn () => AuditLog::query()->where('subject_id', $this->user->id)->whereIn('action', ['auth.account.suspended', 'auth.account.active', 'auth.account.revoked'])->count()))->toBe(3);
});

it('does not let people lock themselves out, act without user.manage, or touch a Super Admin', function () {
    $svc = app(AccountStatusService::class);
    actAs($this->owner);
    expect(fn () => $svc->suspend($this->owner, 'testing myself'))->toThrow(BusinessRuleViolation::class, 'your own account');
    expect(fn () => $svc->suspend($this->user, 'no'))->toThrow(BusinessRuleViolation::class, 'reason');
    $sa = Make::owner($this->entity);
    expect(fn () => $svc->suspend($sa, 'owner tries'))->toThrow(BusinessRuleViolation::class, 'Only an Owner');

    actAs(Make::account($this->entity, 'EXECUTIVE'));
    expect(fn () => $svc->suspend($this->user, 'not my job'))->toThrow(BusinessRuleViolation::class, "'user.manage'");
});

it('suspends, reinstates and revokes from the Users screen', function () {
    $this->actingAs($this->owner, 'web')->withSession(Make::webSession($this->owner));
    actAs($this->owner);
    $screen = fn () => Livewire::test(ListLoginAccounts::class);
    $id = $this->user->getKey();
    $screen()->callTableAction('suspend', $id, ['reason' => 'Investigation'])->assertNotified("{$this->user->username} is now suspended.");
    $screen()->callTableAction('reinstate', $id, ['reason' => 'Cleared'])->assertNotified("{$this->user->username} is now active.");
    $screen()->callTableAction('revoke', $id, ['reason' => 'Left the company']);
    $screen()->assertTableActionHidden('reinstate', $id)->assertTableActionHidden('suspend', $id);
    expect(Make::system(fn () => $this->user->fresh()->status))->toBe('revoked');
});
