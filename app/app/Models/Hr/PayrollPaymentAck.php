<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\StoredFile;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The employee's acknowledgement of cash received: a photo of the signature or thumb impression on
 * the cash register. Append-only.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $payroll_payment_id
 * @property string $method signature|thumb
 * @property string $file_id
 * @property Carbon $created_at
 * @property-read StoredFile $file
 */
class PayrollPaymentAck extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['payroll_payment_id', 'method', 'file_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<StoredFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
