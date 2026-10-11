<?php

declare(strict_types=1);

use App\Domain\Audit\AuditHasher;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\AuditVerifier;
use App\Domain\Auth\AuthContextBinder;
use App\Models\AuditLog;
use App\Models\Entity;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('AUD');
    $this->owner = Make::account($this->entity, 'OWNER');
    app(AuthContextBinder::class)->bind($this->owner);
});

function rawAudit(): Builder
{
    return DB::table('audit_log');
}

it('records who, what, when, device, IP, old and new values', function () {
    $this->entity->name = 'Renamed Mill';
    $this->entity->save();

    $log = AuditLog::query()->where('action', 'entity.updated')->latest('occurred_at')->firstOrFail();

    expect($log->actor_login_account_id)->toBe($this->owner->id)
        ->and($log->actor_person_id)->toBe($this->owner->person_id)
        ->and($log->entity_id)->toBe($this->entity->id)
        ->and($log->subject_type)->toBe('entities')
        ->and($log->subject_id)->toBe($this->entity->id)
        ->and($log->old_values)->toBe(['name' => 'Mill AUD'])
        ->and($log->new_values)->toBe(['name' => 'Renamed Mill'])
        ->and($log->occurred_at)->not->toBeNull();
});

it('masks confidential values and never stores secrets', function () {
    Make::system(function () {
        $this->entity->ntn = '12345678';
        $this->entity->save();
        $this->owner->password = 'Another-Secret-99!';
        $this->owner->save();
    });

    $panLog = AuditLog::query()->where('action', 'entity.updated')->latest('occurred_at')->firstOrFail();
    $pwLog = AuditLog::query()->where('action', 'login_account.updated')->latest('occurred_at')->firstOrFail();

    expect($panLog->new_values['ntn'])->toBe('****5678')
        ->and(json_encode($pwLog->new_values))->not->toContain('Another-Secret')
        ->and($pwLog->new_values['password'])->toBe('[set]');
});

it('chains every entry to the previous one without gaps', function () {
    $logger = app(AuditLogger::class);
    foreach (range(1, 5) as $i) {
        $logger->record('test.event', meta: ['i' => $i]);
    }

    $rows = rawAudit()->where('chain', "entity:{$this->entity->id}")->orderBy('seq')->get();
    expect($rows->pluck('seq')->map(fn ($s) => (int) $s)->all())->toBe(range(1, $rows->count()));

    foreach ($rows as $i => $row) {
        $expectedPrev = $i === 0 ? str_repeat('0', 64) : $rows[$i - 1]->hash;
        expect($row->prev_hash)->toBe($expectedPrev);
    }

    expect(app(AuditVerifier::class)->verify()->ok())->toBeTrue();
});

it('cannot be updated, deleted or truncated by the application database user', function () {
    $id = rawAudit()->value('id');
    // Each attempt runs in its own savepoint so one failure cannot mask the next.
    $attempt = fn (callable $query) => fn () => DB::transaction($query);

    expect($attempt(fn () => rawAudit()->where('id', $id)->update(['action' => 'tampered'])))
        ->toThrow(QueryException::class, 'append-only')
        ->and($attempt(fn () => rawAudit()->where('id', $id)->delete()))
        ->toThrow(QueryException::class, 'append-only');

    if (DB::getDriverName() === 'pgsql') {
        expect($attempt(fn () => DB::statement('TRUNCATE audit_log')))->toThrow(QueryException::class, 'append-only');
    }

    expect(rawAudit()->where('id', $id)->value('action'))->not->toBe('tampered');
});

it('cannot be changed through the model either', function () {
    $log = AuditLog::query()->firstOrFail();
    $log->action = 'tampered';

    expect(fn () => $log->save())->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

/*
 * Simulates someone with table-owner rights bypassing the trigger, then verifies.
 * PostgreSQL only: on MariaDB the required DDL would implicitly commit the test
 * transaction. The verifier code path is identical on both databases (see the
 * AuditHashingTest unit test for cross-driver hash equivalence).
 */
describe('tamper detection', function () {
    function tamper(callable $change): void
    {
        Make::system(function () use ($change) {
            DB::statement('ALTER TABLE audit_log DISABLE TRIGGER audit_log_no_update_delete');
            $change();
            DB::statement('ALTER TABLE audit_log ENABLE TRIGGER audit_log_no_update_delete');
        });
    }

    beforeEach(function () {
        requiresPostgres();
        $logger = app(AuditLogger::class);
        foreach (range(1, 3) as $i) {
            $logger->record('test.event', meta: ['amount_paise' => 100 * $i]);
        }
        $this->chain = "entity:{$this->entity->id}";
        expect(app(AuditVerifier::class)->verify()->ok())->toBeTrue();
    });

    it('detects an edited value', function () {
        $seq = (int) Make::system(fn () => rawAudit()->where('chain', $this->chain)->where('action', 'test.event')->min('seq'));
        tamper(fn () => rawAudit()->where('chain', $this->chain)->where('seq', $seq)->update(['meta' => '{"amount_paise":1}']));

        $result = app(AuditVerifier::class)->verify();
        expect($result->ok())->toBeFalse()
            ->and($result->failures[0])->toMatchArray(['chain' => $this->chain, 'seq' => $seq, 'error' => 'row content does not match its hash']);
    });

    it('detects a deleted entry', function () {
        $seq = (int) Make::system(fn () => rawAudit()->where('chain', $this->chain)->where('action', 'test.event')->min('seq'));
        tamper(fn () => rawAudit()->where('chain', $this->chain)->where('seq', $seq)->delete());

        $result = app(AuditVerifier::class)->verify();
        expect($result->ok())->toBeFalse()
            ->and($result->failures[0]['error'])->toContain("expected seq {$seq}");
    });

    it('detects removal of the newest entries', function () {
        $max = (int) Make::system(fn () => rawAudit()->where('chain', $this->chain)->max('seq'));
        tamper(fn () => rawAudit()->where('chain', $this->chain)->where('seq', $max)->delete());

        $result = app(AuditVerifier::class)->verify();
        expect($result->ok())->toBeFalse()
            ->and($result->failures[0]['error'])->toContain('chain head');
    });

    it('detects a re-hashed forgery that does not link to the next entry', function () {
        $seq = (int) Make::system(fn () => rawAudit()->where('chain', $this->chain)->where('action', 'test.event')->min('seq'));
        tamper(function () use ($seq) {
            $row = (array) rawAudit()->where('chain', $this->chain)->where('seq', $seq)->first();
            $row['meta'] = '{"amount_paise":1}';
            $forged = AuditHasher::hash($row['prev_hash'], $row);
            rawAudit()->where('id', $row['id'])->update(['meta' => $row['meta'], 'hash' => $forged]);
        });

        $result = app(AuditVerifier::class)->verify();
        expect($result->ok())->toBeFalse()
            ->and($result->failures[0])->toMatchArray(['seq' => $seq + 1, 'error' => 'prev_hash does not match previous row']);
    });
});

it('exposes verification through artisan', function () {
    $this->artisan('audit:verify')->expectsOutputToContain('Audit log intact.')->assertSuccessful();
});

it('writes the audit row in the same transaction as the change', function () {
    $before = Make::system(fn () => rawAudit()->count());

    try {
        DB::transaction(function () {
            Entity::query()->whereKey($this->entity->id)->firstOrFail()->update(['name' => 'Will roll back']);
            throw new RuntimeException('business rule failed');
        });
    } catch (RuntimeException) {
    }

    expect(Make::system(fn () => rawAudit()->count()))->toBe($before)
        ->and(app(AuditVerifier::class)->verify()->ok())->toBeTrue();
});
