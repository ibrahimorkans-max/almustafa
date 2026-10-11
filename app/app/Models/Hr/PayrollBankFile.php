<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A salary transfer file for the bank portal; the SHA-256 shows it was not edited before upload.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $payroll_run_id
 * @property string $number
 * @property string $ledger_account_id
 * @property string $format
 * @property int $payment_count
 * @property int $total_paise
 * @property string $sha256
 * @property string|null $voucher_id
 * @property Carbon $created_at
 */
class PayrollBankFile extends Model
{
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const UPDATED_AT = null;

    protected $fillable = ['payroll_run_id', 'number', 'ledger_account_id', 'format', 'payment_count', 'total_paise', 'sha256', 'voucher_id', 'created_at'];

    protected function casts(): array
    {
        return ['payment_count' => 'integer', 'total_paise' => 'integer', 'created_at' => 'datetime'];
    }
}
