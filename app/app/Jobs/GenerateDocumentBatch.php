<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Auth\ActorContext;
use App\Domain\Documents\Engine\DocumentBatches;
use App\Domain\Tenancy\TenantContext;
use App\Models\Documents\DocumentBatch;
use App\Models\LoginAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Issues a bulk batch of documents on the queue, in the mill and as the person who started it (so
 * the same permissions apply and the documents and logs show who made them). Retried with back-off;
 * progress is kept, so a retry continues where the last attempt stopped.
 */
final class GenerateDocumentBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 1800;

    public function __construct(public readonly string $batchId) {}

    public function handle(TenantContext $tenant, ActorContext $actor, DocumentBatches $batches): void
    {
        [$batch, $account] = $tenant->runAsSystem(function (): array {
            $batch = DocumentBatch::query()->findOrFail($this->batchId);

            return [$batch, LoginAccount::query()->find($batch->created_by)];
        });
        if ($batch->status === DocumentBatch::DONE || $account === null) {
            return;
        }
        [$previous, $session, $device] = [$actor->account(), $actor->session(), $actor->deviceId()];
        $actor->set($account);
        try {
            $tenant->runInEntity($batch->entity_id, fn () => $batches->run(DocumentBatch::query()->findOrFail($batch->id)));
        } finally {
            $previous !== null ? $actor->set($previous, $session, $device) : $actor->clear();
        }
    }

    public function failed(Throwable $e): void
    {
        app(TenantContext::class)->runAsSystem(fn () => DocumentBatch::query()->whereKey($this->batchId)
            ->update(['status' => DocumentBatch::FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]));
    }
}
