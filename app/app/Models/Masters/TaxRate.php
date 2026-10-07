<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\LoginAccount;
use Illuminate\Validation\Rule;

/**
 * GST rate in basis points (500 = 5 %).
 *
 * @property string $name
 * @property int $gst_rate_bp
 * @property int $cess_rate_bp
 * @property string $status
 */
class TaxRate extends MasterModel
{
    public const SYNC_TYPE = 'tax_rate';

    /** Current GST slabs. */
    public const GST_SLABS_BP = [0, 25, 300, 500, 1200, 1800, 2800];

    protected $fillable = ['name', 'gst_rate_bp', 'cess_rate_bp', 'status'];

    protected function casts(): array
    {
        return ['gst_rate_bp' => 'integer', 'cess_rate_bp' => 'integer', 'version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function percentLabel(): string
    {
        return rtrim(rtrim(number_format($this->gst_rate_bp / 100, 2, '.', ''), '0'), '.').'%';
    }

    public static function writeRules(?MasterModel $existing): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'gst_rate_bp' => ['required', 'integer', Rule::in(self::GST_SLABS_BP)],
            'cess_rate_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public static function writableFields(): array
    {
        return ['name', 'gst_rate_bp', 'cess_rate_bp', 'status'];
    }

    public function toSyncArray(LoginAccount $viewer): array
    {
        return $this->syncEnvelope() + [
            'name' => $this->name,
            'gst_rate_bp' => $this->gst_rate_bp,
            'cess_rate_bp' => $this->cess_rate_bp,
            'status' => $this->status,
        ];
    }
}
