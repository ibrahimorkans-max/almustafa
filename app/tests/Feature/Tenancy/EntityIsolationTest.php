<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Tenancy\Exceptions\CrossEntityWrite;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Make;

beforeEach(function () {
    $this->a = Make::entity('AAA');
    $this->b = Make::entity('BBB');
    $this->ownerA = Make::account($this->a, 'SUPER_ADMIN');
    $this->ownerB = Make::account($this->b, 'SUPER_ADMIN');
    Make::trustedDevice($this->ownerB, 'device-of-b-0000001', 'android');
    Make::reset();
});

describe('application scope (all databases)', function () {
    it('shows an entity only its own rows', function () {
        app(AuthContextBinder::class)->bind($this->ownerA);

        expect(LoginAccount::query()->pluck('id')->all())->toBe([$this->ownerA->id])
            ->and(Entity::query()->pluck('id')->all())->toBe([$this->a->id])
            ->and(Role::query()->whereNotNull('entity_id')->pluck('entity_id')->unique()->all())->toBe([$this->a->id])
            ->and(Person::query()->pluck('id')->all())->toBe([$this->ownerA->person_id])
            ->and(Device::query()->count())->toBe(0)
            ->and(LoginAccount::query()->find($this->ownerB->id))->toBeNull()
            ->and(Entity::query()->find($this->b->id))->toBeNull();
    });

    it('returns nothing when no entity context is set (fail closed)', function () {
        expect(LoginAccount::query()->count())->toBe(0)
            ->and(Entity::query()->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0)
            ->and(Person::query()->count())->toBe(0);
    });

    it('fills entity_id from the context and refuses creating without one', function () {
        app(AuthContextBinder::class)->bind($this->ownerA);
        $device = Device::query()->create([
            'login_account_id' => $this->ownerA->id, 'device_uid' => 'x-1234567890123456', 'platform' => 'android',
        ]);
        expect($device->entity_id)->toBe($this->a->id);

        Make::reset();
        expect(fn () => Device::query()->create([
            'login_account_id' => $this->ownerA->id, 'device_uid' => 'x-2234567890123456', 'platform' => 'android',
        ]))->toThrow(MissingTenantContext::class);
    });

    it('refuses writing a row into another entity', function () {
        app(AuthContextBinder::class)->bind($this->ownerA);

        expect(fn () => Device::query()->create([
            'entity_id' => $this->b->id, 'login_account_id' => $this->ownerB->id,
            'device_uid' => 'x-3234567890123456', 'platform' => 'android',
        ]))->toThrow(CrossEntityWrite::class);
    });

    it('refuses moving a row to another entity', function () {
        app(AuthContextBinder::class)->bind($this->ownerA);
        $device = Device::query()->create([
            'login_account_id' => $this->ownerA->id, 'device_uid' => 'x-4234567890123456', 'platform' => 'android',
        ]);

        $device->entity_id = $this->b->id;
        expect(fn () => $device->save())->toThrow(CrossEntityWrite::class);
    });

    it('lets Super Admin read across entities and narrow to a working entity', function () {
        $sa = Make::owner($this->a);
        Make::reset();

        app(AuthContextBinder::class)->bind($sa, workingEntityId: AuthContextBinder::ALL_ENTITIES);
        expect(LoginAccount::query()->whereIn('id', [$this->ownerA->id, $this->ownerB->id])->count())->toBe(2);

        app(AuthContextBinder::class)->bind($sa, workingEntityId: $this->b->id);
        expect(LoginAccount::query()->pluck('id')->all())->toBe([$this->ownerB->id])
            // Super Admin still sees the list of all entities to choose from.
            ->and(Entity::query()->count())->toBe(2);
    });

    it('keeps each entity\'s audit trail separate', function () {
        app(AuthContextBinder::class)->bind($this->ownerA);
        $chains = AuditLog::query()->distinct()->pluck('chain')->all();

        expect($chains)->toBe(["entity:{$this->a->id}"]);
    });
});

describe('PostgreSQL row-level security', function () {
    beforeEach(fn () => requiresPostgres());

    it('hides other entities from raw queries that forget the scope', function () {
        app(TenantContext::class)->enterEntity($this->a->id);

        $accounts = DB::table('login_accounts')->pluck('entity_id')->unique()->values()->all();
        $devices = DB::table('devices')->pluck('entity_id')->unique()->values()->all();
        $audit = DB::table('audit_log')->pluck('entity_id')->unique()->values()->all();
        $entities = DB::table('entities')->pluck('id')->all();

        expect($accounts)->toBe([$this->a->id])
            ->and($devices)->toBe([])
            ->and($audit)->toBe([$this->a->id])
            ->and($entities)->toBe([$this->a->id]);
    });

    it('returns no tenant rows at all to a raw query without context', function () {
        expect(DB::table('login_accounts')->count())->toBe(0)
            ->and(DB::table('entities')->count())->toBe(0)
            ->and(DB::table('audit_log')->count())->toBe(0)
            ->and(DB::table('approval_requests')->count())->toBe(0)
            // only the global Super Admin role is readable without context
            ->and(DB::table('roles')->pluck('code')->all())->toBe(['OWNER']);
    });

    it('rejects a raw insert into another entity', function () {
        app(TenantContext::class)->enterEntity($this->a->id);

        DB::beginTransaction();
        try {
            expect(fn () => DB::table('approval_requests')->insert([
                'id' => (string) Str::uuid(), 'entity_id' => $this->b->id, 'action' => 'x', 'payload' => '{}',
                'summary' => 'x', 'status' => ApprovalRequest::STATUS_PENDING,
                'maker_login_account_id' => $this->ownerA->id, 'maker_person_id' => $this->ownerA->person_id,
                'created_at' => now(), 'updated_at' => now(),
            ]))->toThrow(QueryException::class, 'row-level security');
        } finally {
            DB::rollBack();
        }
    });

    it('does not let a raw update or delete touch another entity', function () {
        app(TenantContext::class)->enterEntity($this->a->id);

        $updated = DB::table('login_accounts')->where('id', $this->ownerB->id)->update(['status' => 'suspended']);
        $deleted = DB::table('devices')->where('entity_id', $this->b->id)->delete();

        expect($updated)->toBe(0)->and($deleted)->toBe(0);
        expect(Make::system(fn () => LoginAccount::query()->find($this->ownerB->id)->status))->toBe('active')
            ->and(Make::system(fn () => Device::query()->where('entity_id', $this->b->id)->count()))->toBe(1);
    });

    it('applies to the table owner too (FORCE ROW LEVEL SECURITY)', function () {
        $forced = DB::table('pg_class')->whereIn('relname', ['login_accounts', 'audit_log', 'entities', 'persons'])
            ->pluck('relforcerowsecurity')->all();

        expect($forced)->each->toBeTrue();
    });
});
