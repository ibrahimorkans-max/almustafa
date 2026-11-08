<?php

declare(strict_types=1);

namespace App\Models\Usage;

use App\Models\Entity;
use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One call to an outside service (App\Domain\Usage\UsageMeter). Never changed or removed (the database refuses);
 * what is learned later (delivered, the provider's own cost, reconciled) is a ServiceUsageUpdate beside it.
 * A mill's people see only their mill's rows (row-level security); the SaaS owner sees all.
 *
 * @property string $id
 * @property string $reference
 * @property string|null $customer_id
 * @property string|null $entity_id
 * @property string $channel api | sms | whatsapp | email | push
 * @property string $category
 * @property string $provider
 * @property string $service
 * @property string $module
 * @property string $action
 * @property string|null $description
 * @property string|null $login_account_id
 * @property string|null $process
 * @property string|null $recipient_masked
 * @property int $quantity
 * @property bool $success
 * @property string $estimated_cost
 * @property string $charge
 * @property string $currency
 * @property string|null $actual_cost
 * @property string $billing_status
 * @property Carbon $occurred_at
 */
class ServiceUsage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const CHANNELS = ['api' => 'API', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'push' => 'Push notification'];

    public const CATEGORIES = ['ai' => 'AI', 'messaging' => 'WhatsApp', 'sms' => 'SMS gateway', 'email' => 'E-mail service', 'push' => 'Push notifications',
        'mailbox' => 'Bank mailbox', 'maps' => 'Maps', 'ocr' => 'OCR', 'other' => 'Other integrations'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['success' => 'boolean', 'quantity' => 'integer', 'units' => 'integer', 'meta' => 'array', 'occurred_at' => 'datetime',
            'unit_price' => 'decimal:6', 'estimated_cost' => 'decimal:6', 'charge' => 'decimal:6', 'actual_cost' => 'decimal:6'];
    }

    /** @return HasMany<ServiceUsageUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(ServiceUsageUpdate::class)->orderBy('id');
    }

    /** Sent / delivered / read / failed: the latest delivery report, else whether the call worked. */
    public function deliveryStatus(): string
    {
        $last = $this->relationLoaded('updates') ? $this->updates->where('kind', 'delivery')->last() : $this->updates()->where('kind', 'delivery')->latest('id')->first();

        return $last->status ?? ($this->success ? 'sent' : 'failed');
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'login_account_id');
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /** Who used it: a person, or the automatic process. */
    public function byLabel(): string
    {
        return $this->account?->username ?? ('automatic: '.($this->process ?? 'system'));
    }
}
