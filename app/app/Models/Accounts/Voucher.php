<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A posted voucher. Append-only: only Books::post writes it, a correction is a reversal voucher.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $type
 * @property string $number
 * @property Carbon $voucher_date
 * @property string|null $narration
 * @property int $total_paise
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $reverses_id
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class Voucher extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    /** type => number series */
    public const TYPES = [
        'purchase' => 'PV', 'payment' => 'PAY', 'receipt' => 'RCT', 'journal' => 'JV', 'contra' => 'CV',
        'debit_note' => 'DN', 'credit_note' => 'CN', 'opening' => 'OB', 'sales' => 'SV',
    ];

    protected $fillable = [
        'entity_id', 'type', 'number', 'voucher_date', 'narration', 'total_paise', 'source_type', 'source_id', 'reverses_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['voucher_date' => 'date', 'total_paise' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return HasMany<VoucherLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class)->orderBy('line_no');
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }
}
