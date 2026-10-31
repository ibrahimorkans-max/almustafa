<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Masters\Party;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event WhatsApp sent to the webhook (App\Domain\Messaging\WhatsAppWebhook). Append-only.
 *
 * @property string $id
 * @property string|null $entity_id
 * @property string $scope
 * @property string $kind status | message
 * @property string $wa_message_id
 * @property string $status sent | delivered | read | failed; received (a message)
 * @property string|null $phone
 * @property string|null $party_id
 * @property string|null $type
 * @property string|null $body
 * @property string|null $error
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property-read Party|null $party
 */
class WhatsAppEvent extends Model
{
    use AppendOnly;
    use HasUuids;

    public const STATUS = 'status';

    public const MESSAGE = 'message';

    protected $table = 'whatsapp_events';

    public $timestamps = false;

    protected $fillable = ['entity_id', 'scope', 'kind', 'wa_message_id', 'status', 'phone', 'party_id', 'type', 'body', 'error', 'occurred_at', 'created_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** Words for a message's progress. */
    public const PROGRESS = ['sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'failed' => 'Not delivered', 'received' => 'Received'];

    /** The furthest WhatsApp has reported for one message we sent (read beats delivered beats sent; failed is final). */
    public static function progressOf(?string $waMessageId): ?string
    {
        if ($waMessageId === null || $waMessageId === '') {
            return null;
        }
        $states = self::query()->where('wa_message_id', $waMessageId)->where('kind', self::STATUS)->pluck('status')->all();
        foreach (['failed', 'read', 'delivered', 'sent'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return null;
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
