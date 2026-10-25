<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message to a party (App\Domain\Messaging\PartyMessenger).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $party_id
 * @property string $event login | money | unloaded | weighed
 * @property string $channel sms | whatsapp
 * @property string $recipient
 * @property string|null $body null when it carried a password
 * @property bool $redacted
 * @property string $status sent | manual | failed | skipped
 * @property string|null $error
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $created_by
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 * @property-read Party $party
 */
class PartyMessage extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const SENT = 'sent';

    /** No gateway configured: waiting for the office to send it by hand (WhatsApp link). */
    public const MANUAL = 'manual';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = ['party_id', 'event', 'channel', 'recipient', 'body', 'redacted', 'status', 'error', 'subject_type', 'subject_id', 'created_by', 'sent_at'];

    protected function casts(): array
    {
        return ['redacted' => 'boolean', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
