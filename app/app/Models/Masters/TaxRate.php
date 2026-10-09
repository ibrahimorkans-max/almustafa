<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\LoginAccount;
use Illuminate\Validation\Rule;

/**
 * Sales-tax rate in basis points (1800 = 18 %, the standard rate). "Exempt" (Sixth Schedule) differs
 * from a 0 % (zero-rated) supply on the return, so it is a separate flag; both charge no tax.
 *
 * @property string $name
 * @property int $sales_tax_rate_bp
 * @property bool $exempt
 * @property string $status
 */
class TaxRate extends MasterModel
{
    public const SYNC_TYPE = 'tax_rate';

    /** Highest rate accepted (25 %), to catch typing mistakes; the standard rate is set by the Finance Act. */
    public const MAX_RATE_BP = 2500;

    /** Installed for every new mill: [name, rate bp, exempt]. The tax adviser adjusts or adds rates. */
    public const DEFAULTS = [['Exempt (Sixth Schedule)', 0, true], ['Standard 18%', 1800, false]];

    protected $fillable = ['name', 'sales_tax_rate_bp', 'exempt', 'status'];

    protected function casts(): array
    {
        return ['sales_tax_rate_bp' => 'integer', 'exempt' => 'boolean', 'version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function percentLabel(): string
    {
        return $this->exempt ? 'Exempt' : rtrim(rtrim(number_format($this->sales_tax_rate_bp / 100, 2, '.', ''), '0'), '.').'%';
    }

    public static function writeRules(?MasterModel $existing): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'sales_tax_rate_bp' => ['required', 'integer', 'min:0', 'max:'.self::MAX_RATE_BP],
            'exempt' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public static function writableFields(): array
    {
        return ['name', 'sales_tax_rate_bp', 'exempt', 'status'];
    }

    public function toSyncArray(LoginAccount $viewer): array
    {
        return $this->syncEnvelope() + [
            'name' => $this->name,
            'sales_tax_rate_bp' => $this->sales_tax_rate_bp,
            'exempt' => $this->exempt,
            'status' => $this->status,
        ];
    }
}
