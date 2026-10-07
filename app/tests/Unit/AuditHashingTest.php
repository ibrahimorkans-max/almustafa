<?php

declare(strict_types=1);

use App\Domain\Audit\AuditHasher;
use App\Domain\Audit\CanonicalJson;

it('produces the same JSON regardless of key order', function () {
    expect(CanonicalJson::encode(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]))
        ->toBe(CanonicalJson::encode(['a' => ['x' => 1, 'y' => 2], 'b' => 1]))
        ->toBe('{"a":{"x":1,"y":2},"b":1}');
});

it('keeps list order and unicode', function () {
    expect(CanonicalJson::encode(['names' => ['चावल', 'Basmati 1121']]))->toBe('{"names":["चावल","Basmati 1121"]}');
});

it('hashes identically whatever representation the database returns', function () {
    $written = [
        'id' => 'a', 'chain' => 'global', 'seq' => 7, 'occurred_at' => '2026-10-07 10:00:00.000000',
        'acting_as_super_admin' => false, 'action' => 'x', 'meta' => '{"k":1}',
    ];
    // PostgreSQL returns bool + trimmed microseconds; MariaDB returns "0" + strings.
    $readPg = ['seq' => '7', 'occurred_at' => '2026-10-07 10:00:00', 'acting_as_super_admin' => false] + $written;
    $readMy = ['seq' => '7', 'acting_as_super_admin' => 0] + $written;

    $hash = AuditHasher::hash(AuditHasher::GENESIS, $written);

    expect(AuditHasher::hash(AuditHasher::GENESIS, $readPg))->toBe($hash)
        ->and(AuditHasher::hash(AuditHasher::GENESIS, (object) $readMy))->toBe($hash)
        ->and(AuditHasher::hash(AuditHasher::GENESIS, ['meta' => '{"k":2}'] + $written))->not->toBe($hash)
        ->and(AuditHasher::hash(str_repeat('1', 64), $written))->not->toBe($hash);
});
