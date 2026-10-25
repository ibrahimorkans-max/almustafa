<?php

declare(strict_types=1);

use App\Domain\Access\EntityProvisioner;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Security\Lockdown;
use App\Domain\Security\SecurityNotifier;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\Exceptions\CrossEntityWrite;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\Make;

/** A mill of the given customer, with one party in it. */
function isoMill(string $code, string $customer): Entity
{
    $entity = app(EntityProvisioner::class)->create([
        'code' => $code, 'name' => "Mill {$code}", 'status' => Entity::STATUS_ACTIVE, 'customer_id' => Make::customer($customer)->id,
    ]);
    Make::system(fn () => app(TenantContext::class)->runInEntity($entity->id, fn () => Party::query()->create(['code' => 'P-'.$code, 'name' => 'Party of '.$code, 'is_vendor' => true])));

    return $entity;
}

/** An Owner across all firms of its customer (or the platform admin). */
function isoBindAll(LoginAccount $account): void
{
    app(AuthContextBinder::class)->bind($account, null, null, AuthContextBinder::ALL_ENTITIES);
}

beforeEach(function () {
    $this->a1 = isoMill('A1', 'CUSTA');
    $this->a2 = isoMill('A2', 'CUSTA');
    $this->b1 = isoMill('B1', 'CUSTB');
    $this->ownerA = Make::account($this->a1, 'OWNER', ['mfa' => true]);
    $this->ownerB = Make::account($this->b1, 'OWNER', ['mfa' => true]);
    $this->platform = Make::account($this->a1, 'OWNER', ['mfa' => true]);
    Make::system(fn () => $this->platform->forceFill(['is_platform_admin' => true])->save());
    Make::reset();
});

it('shows an Owner across "all firms" only the mills and records of its own customer', function () {
    isoBindAll($this->ownerA);

    expect(Entity::query()->orderBy('code')->pluck('code')->all())->toBe(['A1', 'A2'])
        ->and(Party::query()->orderBy('code')->pluck('code')->all())->toBe(['P-A1', 'P-A2']);
    if (DB::getDriverName() === 'pgsql') {
        // Below the application: the database itself hides the other customer's rows.
        expect(DB::table('parties')->orderBy('code')->pluck('code')->all())->toBe(['P-A1', 'P-A2'])
            ->and(DB::table('entities')->orderBy('code')->pluck('code')->all())->toBe(['A1', 'A2']);
    }

    isoBindAll($this->ownerB);
    expect(Party::query()->pluck('code')->all())->toBe(['P-B1']);
});

it('lets an Owner write in its own mills only', function () {
    isoBindAll($this->ownerA);
    $mine = Party::query()->where('code', 'P-A2')->sole();
    $mine->update(['name' => 'Renamed in A2']);
    expect($mine->fresh()->name)->toBe('Renamed in A2');

    $theirs = Make::system(fn () => Party::query()->withoutGlobalScopes()->where('code', 'P-B1')->sole());
    expect(fn () => $theirs->update(['name' => 'Taken over']))->toThrow(CrossEntityWrite::class);
    expect(fn () => (new Party(['code' => 'X', 'name' => 'Planted', 'is_vendor' => true]))->forceFill(['entity_id' => $this->b1->id])->save())
        ->toThrow(CrossEntityWrite::class);
});

it('never lets an Owner work in another customer\'s mill, even when asked', function () {
    app(AuthContextBinder::class)->bind($this->ownerA, null, null, $this->b1->id);

    expect(app(TenantContext::class)->entityId())->toBe($this->a1->id)
        ->and(Party::query()->pluck('code')->all())->toBe(['P-A1']);
});

it('keeps the platform admin able to see every customer', function () {
    isoBindAll($this->platform);

    expect(Party::query()->orderBy('code')->pluck('code')->all())->toBe(['P-A1', 'P-A2', 'P-B1']);
});

it('keeps lockdown, all-mills settings and security notices inside one customer', function () {
    $code = fn (LoginAccount $a) => app(Google2FA::class)->getCurrentOtp(Make::system(fn () => $a->fresh()->mfa_secret));

    isoBindAll($this->ownerA);
    expect(fn () => app(Lockdown::class)->lock(null, 'Testing the whole system', $code($this->ownerA)))
        ->toThrow(BusinessRuleViolation::class, 'Only the platform admin')
        ->and(fn () => app(Lockdown::class)->lock($this->b1->id, 'Locking a stranger', $code($this->ownerA)))
        ->toThrow(BusinessRuleViolation::class, 'only your own mills');
    app(Lockdown::class)->lock($this->a2->id, 'Our second mill is compromised', $code($this->ownerA));
    expect(app(Lockdown::class)->isLocked($this->a2->id))->toBeTrue()->and(app(Lockdown::class)->isLocked($this->b1->id))->toBeFalse();

    expect(fn () => app(SettingsService::class)->put(SettingsService::ARCHIVE_RETENTION, 12, null))
        ->toThrow(InvalidArgumentException::class, 'Only the platform admin');

    // A notice about mill A1 reaches customer A's Owner, never customer B's (A also heard of its own A2 lockdown).
    $notices = fn (LoginAccount $a) => Make::system(fn () => DB::table('notifications')->where('notifiable_id', $a->id)->count());
    $beforeA = $notices($this->ownerA);
    $reached = Make::system(fn () => app(SecurityNotifier::class)->owners($this->a1->id, 'Test notice', 'About A1'));
    expect($reached)->toBeGreaterThan(0)
        ->and($notices($this->ownerB))->toBe(0)
        ->and($notices($this->ownerA))->toBe($beforeA + 1);

    isoBindAll($this->platform);
    app(Lockdown::class)->lock(null, 'Platform emergency', $code($this->platform));
    expect(app(Lockdown::class)->blocks($this->ownerB->fresh()))->toBeTrue()
        ->and(app(Lockdown::class)->blocks($this->platform->fresh()))->toBeFalse();
});
