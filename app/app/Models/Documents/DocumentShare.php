<?php

declare(strict_types=1);

namespace App\Models\Documents;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A signed, expiring, revocable link to one document. Only the token's hash is kept.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $generated_document_id
 * @property string $channel link|email|whatsapp|sms
 * @property string|null $recipient
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property int $open_count
 * @property int|null $max_opens
 * @property bool $protected
 * @property-read GeneratedDocument $document
 */
class DocumentShare extends Model
{
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const CHANNELS = ['link' => 'Link', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'];

    protected $fillable = ['generated_document_id', 'channel', 'recipient', 'token_hash', 'expires_at', 'max_opens'];

    protected $hidden = ['token_hash', 'recipient'];

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'open_count' => 'integer',
            'max_opens' => 'integer', 'protected' => 'boolean', 'version' => 'integer',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture() && ($this->max_opens === null || $this->open_count < $this->max_opens);
    }

    /** @return BelongsTo<GeneratedDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }
}
