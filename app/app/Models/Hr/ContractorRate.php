<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use App\Models\Masters\Vehicle;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use App\Support\Values\Weight;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a labour contractor is paid for the weight one of its vehicles unloads (or loads, or carries):
 * per maund, per kg, or per a custom weight (Rs 50 per 100 kg). Charge = billable weight ÷ rate weight × rate.
 *
 * Rates are versions: each runs from `effective_from` to `effective_to` (empty: until replaced). Two active
 * rates of one contractor, vehicle and work never cover the same day; a new version closes the open one
 * before it. A rate once used on a bill keeps its amount and weight (make a new version instead).
 * Rates per vehicle, per bag and per ton saved before stay readable and billable; new ones are by weight.
 *
 * @property string $id
 * @property string $entity_id
 * @property string|null $code CRL-000001
 * @property string $party_id
 * @property string|null $vehicle_id
 * @property string $work unloading | loading | transport
 * @property string $basis maund | kg | custom (older: vehicle | bag | ton)
 * @property string|null $basis_qty
 * @property string|null $basis_unit
 * @property string|null $vehicle_type
 * @property int $rate_paise
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $status
 * @property string|null $note
 * @property-read Party $party
 * @property-read Vehicle|null $vehicle
 */
class ContractorRate extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const WORK = ['unloading' => 'Unloading (inbound)', 'loading' => 'Loading (dispatch)', 'transport' => 'Transport (carriage)'];

    /** Offered for new rates: by weight only. */
    public const BASES = ['maund' => 'Per maund (40 kg)', 'kg' => 'Per kg', 'custom' => 'Per custom weight'];

    /** Saved before rates went by weight; still billed as they were. */
    public const OLD_BASES = ['vehicle' => 'Per vehicle (old)', 'bag' => 'Per bag (old)', 'ton' => 'Per ton (old)'];

    public const CUSTOM_UNITS = ['kg' => 'kg', 'maund' => 'maund', 'ton' => 'ton'];

    protected $fillable = ['party_id', 'vehicle_id', 'work', 'basis', 'basis_qty', 'basis_unit', 'vehicle_type', 'rate_paise', 'effective_from', 'effective_to', 'status', 'note'];

    /** @var array<string, mixed> */
    protected $attributes = ['work' => 'unloading', 'status' => 'active'];

    protected function casts(): array
    {
        return ['rate_paise' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (ContractorRate $r): void {
            if ($r->isDirty(['basis', 'basis_qty', 'basis_unit'])) {
                if (! $r->exists && ! isset(self::BASES[$r->basis])) {
                    throw new BusinessRuleViolation('A contractor is paid by weight: per maund, per kg or per a custom weight.');
                }
                if ($r->basis === 'custom') {
                    if (! isset(self::CUSTOM_UNITS[(string) $r->basis_unit]) || ! is_numeric($r->basis_qty) || bccomp((string) $r->basis_qty, '0', 3) <= 0) {
                        throw new BusinessRuleViolation('Give the custom weight: how much, and in kg, maund or ton.');
                    }
                } else {
                    $r->basis_qty = null;
                    $r->basis_unit = null;
                }
            }
            if ($r->rate_paise <= 0) {
                throw new BusinessRuleViolation('The rate must be more than zero.');
            }
            if ($r->effective_to !== null && $r->effective_to->lt($r->effective_from)) {
                throw new BusinessRuleViolation('"Effective to" cannot be before "effective from".');
            }
            if ($r->exists && $r->isDirty(['rate_paise', 'basis', 'basis_qty', 'basis_unit', 'party_id', 'vehicle_id', 'work']) && $r->usedOnBills()) {
                throw new BusinessRuleViolation("Rate {$r->code} is on a bill already: add a new version from the new date instead of changing it.");
            }
            if ($r->status === 'active') {
                $r->closeOrRefuseOverlap();
            }
        });
        static::creating(function (ContractorRate $r): void {
            if (blank($r->code)) {
                $r->code = sprintf('CRL-%06d', app(DocumentNumbers::class)->nextSerial('CODE-CRL', $r->entity_id ?: null));
            }
        });
    }

    /** "Per maund (40 kg)", "Per 100 kg" … */
    public function basisLabel(): string
    {
        if ($this->basis === 'custom') {
            return 'Per '.self::quantity((string) $this->basis_qty).' '.$this->basis_unit;
        }

        return self::BASES[$this->basis] ?? self::OLD_BASES[$this->basis] ?? $this->basis;
    }

    /** "100.000" → "100", "2.500" → "2.5". */
    public static function quantity(string $qty): string
    {
        return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
    }

    /** The weight the rate is for, in kg (null for the old per-vehicle and per-bag rates). */
    public function basisKg(): ?string
    {
        return match ($this->basis) {
            'kg' => '1', 'maund' => '40', 'ton' => '1000',
            'custom' => bcmul((string) $this->basis_qty, Weight::UNITS[(string) $this->basis_unit] ?? '1', 3),
            default => null,
        };
    }

    /** Charge in paise for a billable weight: weight ÷ rate weight × rate, rounded to the paisa. */
    public function chargeFor(string $billableKg): int
    {
        $basis = $this->basisKg() ?? throw new BusinessRuleViolation("Rate {$this->code} is not by weight.");

        return (int) bcdiv(bcadd(bcdiv(bcmul($billableKg, (string) $this->rate_paise, 6), $basis, 6), '0.5', 6), '1', 0);
    }

    public function usedOnBills(): bool
    {
        return ContractorBillLine::query()->where('source->contractor_rate_id', $this->id)
            ->whereHas('bill', fn ($q) => $q->where('status', '!=', 'rejected'))->exists();
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Another active rate of the same contractor, vehicle and work covering any of these days: an open one that
     * starts before this one is closed the day before (a new version); any other is a conflict.
     */
    private function closeOrRefuseOverlap(): void
    {
        $from = $this->effective_from->toDateString();
        $to = $this->effective_to?->toDateString();
        $others = self::query()->where('party_id', $this->party_id)->where('work', $this->work)->where('status', 'active')
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))
            ->where(fn ($q) => $this->vehicle_id === null
                ? $q->whereNull('vehicle_id')->where(fn ($q) => $this->vehicle_type === null ? $q->whereNull('vehicle_type') : $q->where('vehicle_type', $this->vehicle_type))
                : $q->where('vehicle_id', $this->vehicle_id))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('effective_from', '<=', $to))
            ->get();
        foreach ($others as $o) {
            if ($o->effective_to === null && $o->effective_from->toDateString() < $from) {
                $o->effective_to = $this->effective_from->copy()->subDay();
                $o->saveQuietly();

                continue;
            }
            throw new BusinessRuleViolation("Rate {$o->code} ({$o->basisLabel()}, Rs ".number_format($o->rate_paise / 100, 2).') already covers '
                .$o->effective_from->format('d M Y').($o->effective_to ? ' – '.$o->effective_to->format('d M Y') : ' onwards').' for this contractor, vehicle and work. End it or make it inactive first.');
        }
    }
}
