<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Audit\Auditable;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\PaddyVariety;
use App\Models\Masters\Product;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How a quality parameter above its free limit reduces payable weight or rate.
 *
 *  excess_weight: deduct (value − free) × factor/10000 percent of net weight
 *                 (factor 10000 = "1 % weight per 1 % excess moisture")
 *  rate_cut:      cut factor paisa per maund for every full 1 % above free
 *  per_bag:       deduct factor grams per bag (bag weight, "bardana")
 *  fixed_weight:  deduct factor grams from every lot (a fixed weight, whatever the lab says)
 *  percent_of_net: deduct factor basis points of the net weight (250 = 2.5 %), whatever the lab says
 *
 * A rule may be for one paddy variety only, and applies from its effective date. Its code (DR-000001)
 * is made once and kept.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $product_id
 * @property string $parameter
 * @property string $method
 * @property int $free_limit_bp
 * @property int $factor
 * @property int|null $reject_above_bp
 * @property string $status
 * @property string|null $name
 * @property string|null $code
 * @property string|null $paddy_variety_id
 * @property Carbon|null $effective_from
 * @property string|null $condition_note
 * @property-read Product $product
 */
class DeductionRule extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    /** The lab tests (LabStandards codes and the older fixed ones) and bags. */
    public const PARAMETERS = ['moisture', 'green_grain', 'shell', 'shewad_green', 'broken', 'red_rice', 'dandly', 'foreign_matter', 'damaged', 'immature', 'chalky', 'bags'];

    public const METHODS = ['excess_weight', 'rate_cut', 'per_bag', 'fixed_weight', 'percent_of_net'];

    /** Methods that deduct weight without a lab reading. */
    public const UNCONDITIONAL = ['per_bag', 'fixed_weight', 'percent_of_net'];

    public const METHOD_LABELS = [
        'excess_weight' => 'Weight for excess over a free limit (lab)',
        'rate_cut' => 'Rate cut per full % excess (lab)',
        'per_bag' => 'Bag weight: grams per bag',
        'fixed_weight' => 'Fixed weight per lot',
        'percent_of_net' => 'Percentage of net weight',
    ];

    protected $fillable = ['product_id', 'parameter', 'method', 'free_limit_bp', 'factor', 'reject_above_bp', 'status',
        'name', 'code', 'paddy_variety_id', 'effective_from', 'condition_note'];

    protected static function booted(): void
    {
        // One lab rule per product, test and variety (as before), so a lab reading is never deducted twice.
        static::saving(function (DeductionRule $r): void {
            if (in_array($r->method, self::UNCONDITIONAL, true) || $r->status !== 'active') {
                return;
            }
            $twin = static::query()->where('product_id', $r->product_id)->where('parameter', $r->parameter)->where('status', 'active')
                ->whereNotIn('method', self::UNCONDITIONAL)
                ->where(fn (Builder $q) => $r->paddy_variety_id === null ? $q->whereNull('paddy_variety_id') : $q->where('paddy_variety_id', $r->paddy_variety_id))
                ->when($r->exists, fn (Builder $q) => $q->whereKeyNot($r->id))->exists();
            if ($twin) {
                throw new BusinessRuleViolation('There is already an active '.str_replace('_', ' ', $r->parameter).' rule for this product; change that one.');
            }
        });
        static::creating(function (DeductionRule $r): void {
            if (blank($r->code)) {
                $r->code = sprintf('DR-%06d', app(DocumentNumbers::class)->nextSerial('CODE-DR', $r->entity_id ?: null));
            }
        });
        static::updating(function (DeductionRule $r): void {
            if ($r->isDirty('code') && filled($r->getOriginal('code'))) {
                $r->code = (string) $r->getOriginal('code');
            }
        });
    }

    /**
     * The active rules for a lot of this product (and variety) on this date: rules for every variety and
     * those for its own; a rule with a later effective date does not apply yet.
     *
     * @param  Builder<DeductionRule>  $query
     */
    public function scopeApplicable(Builder $query, string $productId, ?string $varietyId, CarbonInterface $on): void
    {
        $day = CarbonImmutable::instance($on)->timezone('Asia/Karachi')->toDateString();
        $query->where('product_id', $productId)->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('paddy_variety_id')->when($varietyId !== null, fn (Builder $q) => $q->orWhere('paddy_variety_id', $varietyId)))
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $day));
    }

    /** The rule in words: "Moisture over 17 %: weight" or "Fixed 2 kg per lot". */
    public function describe(): string
    {
        return match ($this->method) {
            'fixed_weight' => 'Fixed '.rtrim(rtrim(bcdiv((string) $this->factor, '1000', 3), '0'), '.').' kg per lot',
            'percent_of_net' => rtrim(rtrim(bcdiv((string) $this->factor, '100', 2), '0'), '.').' % of net weight',
            'per_bag' => $this->factor.' g per bag',
            default => ucfirst(str_replace('_', ' ', $this->parameter)).' over '.rtrim(rtrim(bcdiv((string) $this->free_limit_bp, '100', 2), '0'), '.').' %',
        };
    }

    protected function casts(): array
    {
        return ['free_limit_bp' => 'integer', 'factor' => 'integer', 'reject_above_bp' => 'integer', 'version' => 'integer', 'effective_from' => 'date'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<PaddyVariety, $this> */
    public function paddyVariety(): BelongsTo
    {
        return $this->belongsTo(PaddyVariety::class);
    }
}
