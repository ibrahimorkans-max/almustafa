<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One offer or counter-offer in a negotiation (append-only).
 *
 * @property string $id
 * @property string $quote_id
 * @property int $revision
 * @property string $offered_by
 * @property string $quantity_kg
 * @property int $rate_paise_per_maund
 * @property array<string, mixed>|null $terms
 * @property string|null $note
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class QuoteRevision extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['entity_id', 'quote_id', 'revision', 'offered_by', 'quantity_kg', 'rate_paise_per_maund', 'terms', 'note', 'created_at'];

    protected function casts(): array
    {
        return [
            'quantity_kg' => 'decimal:3',
            'rate_paise_per_maund' => 'integer',
            'revision' => 'integer',
            'terms' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
