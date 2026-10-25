<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\TenantContext;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A firm / mill with its own books, stock, parties and settings.
 *
 * @property string $id
 * @property string|null $customer_id the customer (company) this mill belongs to
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $ntn
 * @property string|null $strn
 * @property string|null $province
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $postal_code
 * @property string $status
 * @property string|null $name_ur
 * @property string|null $tagline
 * @property string|null $email
 * @property string|null $website
 * @property string|null $logo_path private file (local disk)
 * @property string|null $emblem_path private file (local disk)
 * @property string|null $color_primary #rrggbb
 * @property string|null $color_accent #rrggbb
 * @property string|null $invoice_terms
 * @property string|null $slip_note
 * @property string|null $footer_note
 * @property string|null $bank_title
 * @property string|null $bank_name
 * @property string|null $bank_account
 * @property string|null $bank_iban
 * @property int $version
 */
class Entity extends Model
{
    use Auditable;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['customer_id', 'code', 'name', 'legal_name', 'ntn', 'strn', 'province', 'phone', 'address', 'city', 'postal_code', 'status',
        // How it prints (Print & branding).
        'name_ur', 'tagline', 'email', 'website', 'logo_path', 'emblem_path', 'color_primary', 'color_accent',
        'invoice_terms', 'slip_note', 'footer_note', 'bank_title', 'bank_name', 'bank_account', 'bank_iban',
    ];

    /** @var list<string> */
    protected array $auditMasked = ['ntn', 'strn'];

    protected static function booted(): void
    {
        // An entity row is visible to its own members; a customer's Owner sees all its mills (to pick
        // one), never another customer's; the system and the platform see all.
        static::addGlobalScope('entity', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if ($context->bypassesIsolation()) {
                return;
            }
            if ($context->customerId() !== null) {
                $builder->where($builder->qualifyColumn('customer_id'), $context->customerId());

                return;
            }
            $context->entityId() === null
                ? $builder->whereRaw('1 = 0')
                : $builder->whereKey($context->entityId());
        });
    }

    protected function casts(): array
    {
        return [
            'ntn' => 'encrypted',
            'strn' => 'encrypted',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return HasMany<LoginAccount, $this> */
    public function loginAccounts(): HasMany
    {
        return $this->hasMany(LoginAccount::class);
    }
}
