<?php

declare(strict_types=1);

namespace App\Models\Documents;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every issue, view, download, reprint, share and link opening of a document. Append-only.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $generated_document_id
 * @property string $action
 * @property string|null $account_id
 * @property string|null $share_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
class DocumentAccessLog extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public const ACTIONS = [
        'issue' => 'Issued', 'view' => 'Viewed', 'download' => 'Downloaded', 'reprint' => 'Reprinted (DUPLICATE)', 'share' => 'Shared',
        'revoke' => 'Link revoked', 'open_link' => 'Opened by link', 'refused_link' => 'Link refused',
    ];

    protected $table = 'document_access_log';

    protected $fillable = ['generated_document_id', 'action', 'account_id', 'share_id', 'ip', 'user_agent', 'meta', 'created_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
