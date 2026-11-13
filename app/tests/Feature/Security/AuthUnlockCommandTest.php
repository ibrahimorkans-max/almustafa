<?php

declare(strict_types=1);

use App\Models\LoginAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Make;

it('unlocks the only Owner from the server, and on request gives a temporary password kept in a private file', function () {
    $mill = Make::entity('UNL');
    $owner = Make::owner($mill);
    Make::system(function () use ($owner) {
        LoginAccount::query()->whereKey($owner->id)->update(['needs_admin_unlock' => true, 'locked_until' => now()->addMinutes(15), 'lockouts_24h' => 3]);
        DB::table('login_address_locks')->insert(['login_account_id' => $owner->id, 'ip_hash' => 'x', 'failures' => 0,
            'locked_until' => now()->addMinutes(15), 'updated_at' => now()]);
    });

    $this->artisan('auth:unlock', ['username' => strtoupper($owner->username)])
        ->expectsOutputToContain('Was locked: until an administrator unlocks it')
        ->expectsOutputToContain('is unlocked')
        ->assertSuccessful();
    $after = Make::system(fn () => LoginAccount::query()->find($owner->id));
    expect($after->isLocked())->toBeFalse()
        ->and(Make::system(fn () => DB::table('login_address_locks')->where('login_account_id', $owner->id)->count()))->toBe(0);

    $file = storage_path('app/private/unlock-'.$owner->username.'.txt');
    $this->artisan('auth:unlock', ['username' => $owner->username, '--reset-password' => true])
        ->expectsOutputToContain('not shown here')
        ->assertSuccessful();
    preg_match('/Temporary password: (\S+)/', File::get($file), $m);
    $after = Make::system(fn () => LoginAccount::query()->find($owner->id));
    expect(Hash::check($m[1], $after->password))->toBeTrue()->and($after->must_change_password)->toBeTrue();
    File::delete($file);

    $this->artisan('auth:unlock', ['username' => 'nobody.here'])->assertFailed();
});

it('tells the server admin the real reason each sign-in was refused', function () {
    $mill = Make::entity('WHY');
    $owner = Make::owner($mill);
    $this->postJson('/api/v1/auth/login', ['username' => $owner->username, 'password' => 'wrong-one', 'device_uid' => 'agent-abcdefabcdefabcdefabcdef', 'platform' => 'agent'])->assertStatus(401);
    $this->postJson('/api/v1/auth/login', ['username' => 'no.such.user', 'password' => 'x', 'device_uid' => 'agent-abcdefabcdefabcdefabcdef', 'platform' => 'agent'])->assertStatus(401);

    $this->artisan('auth:sign-ins', ['username' => $owner->username])
        ->expectsOutputToContain('Wrong password')
        ->assertSuccessful();
    $this->artisan('auth:sign-ins', ['--failed' => true])
        ->expectsOutputToContain('Unknown username')
        ->assertSuccessful();
});
