<?php

declare(strict_types=1);

use App\Domain\Auth\FirstSignIn;
use App\Domain\Shared\BusinessRuleViolation;
use App\Mail\FirstSignInVerificationMail;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use App\Models\Person;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Make;

beforeEach(function () {
    config(['ricemill.auth.first_sign_in_check' => true]);
    Mail::fake();
});

function fsiAccount(LoginAccount $account): LoginAccount
{
    return Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->with(['role', 'person'])->findOrFail($account->id));
}

it('sends a new user to the first sign-in before any screen, with where the sign-in came from', function () {
    $entity = Make::entity('FSI');
    $user = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);
    $session = Make::webSession($user);
    $auth = Make::system(fn () => AuthSession::query()->findOrFail($session['auth_session_id']));
    app(FirstSignIn::class)->capture(fsiAccount($user), $auth, 'uid-1', 'Chrome on Windows', 29.81, 71.63, 12);

    $user = fsiAccount($user);
    expect($user->needsFirstSignIn())->toBeTrue()
        ->and($user->hasPermission('settings.manage'))->toBeFalse()
        ->and($user->first_sign_in)->toMatchArray(['ip' => '127.0.0.1', 'device' => 'Chrome on Windows', 'latitude' => 29.81, 'longitude' => 71.63,
            'accuracy_m' => 12, 'location_source' => 'browser']);

    $this->actingAs($user, 'web')->withSession($session)->get('/admin')->assertRedirect('/admin/first-sign-in');
    $this->actingAs($user, 'web')->withSession($session)->get('/admin/first-sign-in')->assertOk()
        ->assertSee('127.0.0.1')->assertSee('Send verification e-mail');
});

it('confirms the e-mail with the one-time button in the e-mail, then the user gives their details and is in', function () {
    $entity = Make::entity('FSV');
    $user = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);
    $session = Make::webSession($user);

    app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'Ali.Raza@Example.com');
    $url = null;
    Mail::assertSent(FirstSignInVerificationMail::class, function (FirstSignInVerificationMail $mail) use (&$url) {
        $url = $mail->url;

        return $mail->hasTo('ali.raza@example.com');
    });
    expect(fn () => app(FirstSignIn::class)->complete(fsiAccount($user), ['full_name' => 'Ali Raza', 'phone' => '0300-1234567']))
        ->toThrow(BusinessRuleViolation::class, 'Confirm your e-mail');

    // Opened on another device (not signed in): it says so; the link works once.
    $this->get($url)->assertOk()->assertSee('E-mail confirmed');
    expect(fsiAccount($user)->email_verified_at)->not->toBeNull();
    $this->get($url)->assertStatus(410);
    // A changed link is refused by its signature.
    $this->get($url.'x')->assertForbidden();

    app(FirstSignIn::class)->complete(fsiAccount($user), ['full_name' => 'Ali Raza', 'phone' => '0300-1234567']);
    $user = fsiAccount($user);
    expect($user->needsFirstSignIn())->toBeFalse()
        ->and($user->email)->toBe('ali.raza@example.com')
        ->and(Make::system(fn () => Person::query()->withoutGlobalScopes()->find($user->person_id)))
        ->full_name->toBe('Ali Raza')->email->toBe('ali.raza@example.com');

    $this->actingAs($user, 'web')->withSession($session)->get('/admin')->assertOk();
});

it('refuses a bad address and a second e-mail within a minute', function () {
    $user = Make::account(Make::entity('FSR'), 'SUPER_ADMIN', ['mfa' => true]);

    expect(fn () => app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'not-an-email'))->toThrow(BusinessRuleViolation::class);
    app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'a@example.com');
    expect(fn () => app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'a@example.com'))
        ->toThrow(BusinessRuleViolation::class, 'less than a minute ago');
});

it('leaves weighbridge PC logins, party logins and users who finished alone', function () {
    $entity = Make::entity('FSX');
    $machine = Make::account($entity, 'GATE_OPERATOR');
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($machine->id)->update(['is_machine' => true]));
    $done = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($done->id)->update(['first_sign_in_completed_at' => now()]));

    expect(fsiAccount($machine)->needsFirstSignIn())->toBeFalse()
        ->and(fsiAccount($done)->needsFirstSignIn())->toBeFalse();

    config(['ricemill.auth.first_sign_in_check' => false]);
    expect(fsiAccount(Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]))->needsFirstSignIn())->toBeFalse();
});
