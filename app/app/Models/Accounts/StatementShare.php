<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sharing (or printing) of a party statement, with what really happened: "accepted" means WhatsApp or
 * the mail server took it (delivery is not confirmed), "prepared" means a link was made for a person to send.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $party_id
 * @property string $channel whatsapp | email | link | print | pdf
 * @property string|null $recipient
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property array<string, mixed>|null $filters
 * @property string|null $subject
 * @property string $status accepted | prepared | failed | done
 * @property string|null $detail
 * @property Carbon|null $link_expires_at
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read Party $party
 */
class StatementShare extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public $timestamps = false;

    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'link' => 'Link copied', 'print' => 'Printed', 'pdf' => 'PDF downloaded'];

    public const STATUSES = [
        'accepted' => 'Handed over (delivery not confirmed)', 'prepared' => 'Link prepared, to send by hand', 'failed' => 'Failed', 'done' => 'Done',
    ];

    protected $fillable = ['party_id', 'channel', 'recipient', 'period_from', 'period_to', 'filters', 'subject', 'status', 'detail', 'link_expires_at', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date', 'filters' => 'array', 'link_expires_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
