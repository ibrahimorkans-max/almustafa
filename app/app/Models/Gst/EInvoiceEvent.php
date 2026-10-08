<?php

declare(strict_types=1);

namespace App\Models\Gst;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every e-invoice / e-way bill request and answer, kept as an append-only record.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $sales_bill_id
 * @property string $kind
 * @property string $provider
 * @property array<string, mixed> $request
 * @property array<string, mixed>|null $response
 * @property string|null $actor_login_account_id
 * @property Carbon $created_at
 */
class EInvoiceEvent extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    protected $table = 'einvoice_events';

    protected $fillable = ['entity_id', 'sales_bill_id', 'kind', 'provider', 'request', 'response', 'actor_login_account_id', 'created_at'];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'created_at' => 'datetime'];
    }
}
