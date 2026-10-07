<?php

declare(strict_types=1);

use App\Domain\Auth\ActorContext;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Tests\Support\Make;

it('keeps the request context when a job runs inline on the sync connection', function () {
    $entity = Make::entity('QSY');
    $account = Make::account($entity, 'OWNER');
    app(AuthContextBinder::class)->bind($account);

    dispatch(fn () => null)->onConnection('sync');

    expect(app(TenantContext::class)->entityId())->toBe($entity->id)
        ->and(app(ActorContext::class)->accountId())->toBe($account->id);
});

it('clears the context around jobs on a worker connection', function () {
    $entity = Make::entity('QWK');
    app(AuthContextBinder::class)->bind(Make::account($entity, 'OWNER'));
    $job = Mockery::mock(Job::class);
    $job->allows('payload')->andReturn([]);

    event(new JobProcessing('database', $job));
    expect(app(TenantContext::class)->entityId())->toBeNull()
        ->and(app(ActorContext::class)->accountId())->toBeNull();

    app(AuthContextBinder::class)->bind(Make::account($entity, 'OWNER'));
    event(new JobProcessed('database', $job));
    expect(app(TenantContext::class)->entityId())->toBeNull();
});
