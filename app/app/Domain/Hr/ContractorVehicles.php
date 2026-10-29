<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Approvals\SelfApproval;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\Hr\ContractorRate;
use App\Models\Masters\Party;
use App\Models\Masters\Vehicle;
use App\Support\Values\PakistanIds;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A labour contractor's vehicles, and what each token earns it.
 *
 *  addVehicle / linkVehicle  the contractor's own vehicle list. A vehicle works for one contractor: it is
 *           never moved to another; only the Owner or the Super Admin may take it off.
 *  assign   links tokens (gate entries) to a contractor, at the gate, when unloaded, or later from the gate
 *           list. A token belongs to one contractor; only the Owner or the Super Admin may take it off
 *           (before it is billed).
 *  rateFor  the contractor's rate effective on the day the vehicle arrived: for that vehicle if set, else for
 *           its kind of vehicle, else for any.
 *  line     Charge = billable weight ÷ rate weight × rate (per maund, per kg or per custom weight), with a
 *           snapshot of the weights and the rate kept on the bill line. Old per-vehicle / per-bag rates are
 *           still billed as before.
 *
 * ContractorBilling bills a contractor's unbilled tokens of a period together (basis "vehicles").
 */
final class ContractorVehicles
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /** Marks a vendor (or any party) as labour contractor, giving it its CTR code. */
    public function markContractor(Party $party): Party
    {
        if ($party->is_contractor) {
            return $party;
        }
        // From the stored row: the copy passed in may be older (another step marked it meanwhile).
        $stored = Party::query()->findOrFail($party->id);
        if (! $stored->is_contractor) {
            if (! $stored->is_vendor) {
                throw new BusinessRuleViolation("{$stored->name} is not a vendor: a labour contractor must be a vendor party, or be marked Labour contractor under Masters → Parties.");
            }
            $stored->forceFill(['is_contractor' => true])->save();
        }
        $party->setRawAttributes($stored->getAttributes(), true);

        return $party;
    }

    /** @return Collection<int, Vehicle> */
    public function vehiclesOf(Party $contractor): Collection
    {
        return Vehicle::query()->where('contractor_party_id', $contractor->id)->orderBy('registration_no')->get();
    }

    /** Adds a vehicle to the contractor's list (registering it if new). */
    public function addVehicle(Party $contractor, string $registrationNo, ?string $type = null): Vehicle
    {
        $this->requireRight();
        $plate = PakistanIds::normalizeVehicleNumber($registrationNo);
        if (! PakistanIds::isValidVehicleNumber($plate)) {
            throw new BusinessRuleViolation('Not a valid registration number.');
        }
        if ($type !== null && ! in_array($type, Vehicle::TYPES, true)) {
            throw new BusinessRuleViolation('Unknown kind of vehicle.');
        }

        return DB::transaction(function () use ($contractor, $plate, $type): Vehicle {
            $vehicle = Vehicle::query()->where('registration_no', $plate)->first()
                ?? Vehicle::query()->create(['registration_no' => $plate, 'vehicle_type' => $type ?? 'truck', 'status' => 'active']);

            return $this->linkVehicle($vehicle, $contractor);
        });
    }

    public function linkVehicle(Vehicle $vehicle, ?Party $contractor): Vehicle
    {
        $this->requireRight();

        return DB::transaction(function () use ($vehicle, $contractor): Vehicle {
            $v = Vehicle::query()->with('contractor')->lockForUpdate()->findOrFail($vehicle->id);
            if ($contractor !== null && $v->contractor_party_id !== null && $v->contractor_party_id !== $contractor->id) {
                throw new BusinessRuleViolation("{$v->registration_no} already works for ".($v->contractor->name ?? 'another contractor').'; it cannot be linked to another contractor.');
            }
            if ($contractor === null && $v->contractor_party_id !== null && ! SelfApproval::allowed($this->actor->account())) {
                throw new BusinessRuleViolation('Only the Owner or the Super Admin can take a vehicle off its contractor.');
            }
            if ($contractor !== null) {
                $this->markContractor($contractor);
            }
            $before = $v->contractor_party_id;
            $v->forceFill(['contractor_party_id' => $contractor?->id])->save();
            $this->audit->record('vehicle.contractor_linked', $v, ['contractor_party_id' => $before], ['contractor_party_id' => $contractor?->id]);

            return $v;
        });
    }

    /**
     * @param  iterable<GateEntry>  $entries
     * @return int how many were linked
     */
    public function assign(iterable $entries, ?Party $contractor, string $work = 'unloading'): int
    {
        $this->requireRight();
        if (! isset(ContractorRate::WORK[$work])) {
            throw new BusinessRuleViolation('Unloading, loading or transport.');
        }
        if ($contractor !== null) {
            $this->markContractor($contractor);
        }

        return DB::transaction(function () use ($entries, $contractor, $work): int {
            $n = 0;
            foreach ($entries as $entry) {
                $entry = GateEntry::query()->lockForUpdate()->findOrFail($entry->id);
                if ($entry->contractor_bill_id !== null) {
                    throw new BusinessRuleViolation("Token {$entry->tokenLabel()} is already on a contractor bill.");
                }
                if ($entry->status === 'cancelled') {
                    throw new BusinessRuleViolation("Token {$entry->tokenLabel()} was cancelled.");
                }
                // A token belongs to one contractor: it is never moved to another.
                if ($contractor !== null && $entry->contractor_party_id !== null && $entry->contractor_party_id !== $contractor->id) {
                    throw new BusinessRuleViolation("Token {$entry->tokenLabel()} is already linked to ".($entry->contractor->name ?? 'another contractor').'; it cannot be linked to another contractor.');
                }
                // Taking a link off (a mistake) is for the Owner or the Super Admin, and recorded.
                if ($contractor === null && $entry->contractor_party_id !== null && ! SelfApproval::allowed($this->actor->account())) {
                    throw new BusinessRuleViolation('Only the Owner or the Super Admin can take a vehicle off its contractor.');
                }
                $before = $entry->contractor_party_id;
                $entry->forceFill(['contractor_party_id' => $contractor?->id, 'contractor_work' => $contractor === null ? null : $work])->save();
                $this->audit->record('gate.contractor_assigned', $entry, ['contractor_party_id' => $before], ['contractor_party_id' => $contractor?->id, 'work' => $work]);
                $n++;
            }

            return $n;
        });
    }

    /** The rate effective on a day: for that vehicle, else its kind of vehicle, else any vehicle. */
    public function rateOn(Party $contractor, string $work, string $day, ?string $vehicleId, ?string $vehicleType): ?ContractorRate
    {
        return ContractorRate::query()->where('party_id', $contractor->id)->where('work', $work)
            ->where('status', 'active')->where('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('vehicle_id')->where(fn ($q) => $q->whereNull('vehicle_type')->orWhere('vehicle_type', $vehicleType)))
                ->when($vehicleId !== null, fn ($q) => $q->orWhere('vehicle_id', $vehicleId)))
            ->orderByRaw('CASE WHEN vehicle_id IS NOT NULL THEN 0 WHEN vehicle_type IS NOT NULL THEN 1 ELSE 2 END')->orderByDesc('effective_from')
            ->first();
    }

    public function rateFor(Party $contractor, GateEntry $entry): ?ContractorRate
    {
        $day = CarbonImmutable::instance($entry->arrived_at)->timezone('Asia/Karachi')->toDateString();

        // A token taken before its vehicle was registered is known by its number.
        $vehicleId = $entry->vehicle_id ?? ($entry->registration_no !== '' ? Vehicle::query()->where('registration_no', $entry->registration_no)->value('id') : null);
        $type = $entry->vehicle_type ?? ($vehicleId !== null ? Vehicle::query()->whereKey($vehicleId)->value('vehicle_type') : null);

        return $this->rateOn($contractor, $entry->contractor_work ?? 'unloading', $day, $vehicleId, $type);
    }

    /**
     * The lines a bill can carry now, and the tokens left out because no rate covers their day (they stay
     * unbilled until a rate from that day is set, so one missing rate never blocks the whole bill).
     *
     * @param  Collection<int, GateEntry>  $entries
     * @return array{lines: list<array<string, mixed>>, skipped: list<string>}
     */
    public function billable(Party $contractor, Collection $entries): array
    {
        $lines = [];
        $skipped = [];
        foreach ($entries as $e) {
            if ($this->rateFor($contractor, $e) === null) {
                $skipped[] = $this->tokenName($e).' ('.CarbonImmutable::instance($e->arrived_at)->timezone('Asia/Karachi')->format('d M').')';

                continue;
            }
            $lines[] = $this->line($contractor, $e);
        }

        return ['lines' => $lines, 'skipped' => $skipped];
    }

    /** "Token 000012 · LEA-4567", or the gate number for a token from before the numeric series. */
    public function tokenName(GateEntry $e): string
    {
        return ($e->token_serial !== null ? 'Token '.$e->tokenLabel() : $e->number).' · '.$e->vehicleLabel();
    }

    /** The weight the contractor is paid for: the final net weight (after paddy taken back off the vehicle). */
    public static function billableKg(GateEntry $entry): ?string
    {
        return $entry->net_kg !== null && bccomp((string) $entry->net_kg, '0', 3) > 0 ? (string) $entry->net_kg : null;
    }

    /**
     * The contractor's tokens in a period not billed yet whose weight is final.
     *
     * @return Collection<int, GateEntry>
     */
    public function unbilled(Party $contractor, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->inPeriod($contractor, $from, $to)->whereNotNull('net_kg')->where('net_kg', '>', 0)->orderBy('arrived_at')->get();
    }

    /** Tokens of the period still waiting for their final weight (not billable yet). */
    public function awaitingWeight(Party $contractor, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->inPeriod($contractor, $from, $to)->where(fn ($q) => $q->whereNull('net_kg')->orWhere('net_kg', '<=', 0))->count();
    }

    /**
     * One bill line for a token.
     *
     * @return array{employee_id: null, description: string, quantity: string, unit: string, rate_paise: int, amount_paise: int, source: array<string, mixed>}
     */
    public function line(Party $contractor, GateEntry $entry): array
    {
        $rate = $this->rateFor($contractor, $entry)
            ?? throw new BusinessRuleViolation("No rate for {$contractor->name} for {$this->tokenName($entry)} on "
                .CarbonImmutable::instance($entry->arrived_at)->timezone('Asia/Karachi')->format('d M Y').': set it under HR → Contractor rates.');
        $billable = self::billableKg($entry);
        $basisKg = $rate->basisKg();
        if ($basisKg !== null) {
            if ($billable === null) {
                throw new BusinessRuleViolation("Token {$entry->tokenLabel()} has no final weight yet.");
            }
            $qty = bcdiv(bcadd(bcdiv($billable, $basisKg, 6), '0.0005', 6), '1', 3);
            $amount = $rate->chargeFor($billable);
            $unit = $rate->basis === 'custom' ? mb_substr(ContractorRate::quantity((string) $rate->basis_qty).' '.$rate->basis_unit, 0, 20) : $rate->basis;
        } else {
            // Old per-vehicle and per-bag rates.
            $qty = match ($rate->basis) {
                'vehicle' => '1.000',
                'bag' => $entry->bag_count !== null && $entry->bag_count > 0 ? number_format($entry->bag_count, 3, '.', '')
                    : throw new BusinessRuleViolation("Token {$entry->tokenLabel()} has no bag count; the rate is per bag."),
                default => throw new BusinessRuleViolation('Unknown rate basis.'),
            };
            $amount = (int) bcdiv(bcadd(bcmul($qty, (string) $rate->rate_paise, 3), '0.5', 3), '1', 0);
            $unit = $rate->basis;
        }

        return [
            'employee_id' => null,
            'description' => sprintf('%s token %s · %s · %s', ucfirst((string) ($entry->contractor_work ?? 'unloading')), $entry->tokenLabel(), $entry->vehicleLabel(),
                CarbonImmutable::instance($entry->arrived_at)->timezone('Asia/Karachi')->format('d M')),
            'quantity' => $qty,
            'unit' => $unit,
            'rate_paise' => $rate->rate_paise,
            'amount_paise' => $amount,
            // The snapshot: the bill keeps what it was charged on, even if the token or the rate changes later.
            'source' => [
                'gate_entry_id' => $entry->id, 'token' => $entry->tokenLabel(), 'vehicle' => $entry->vehicleLabel(),
                'gross_kg' => $entry->gross_kg, 'tare_kg' => $entry->tare_kg, 'net_kg' => $entry->net_kg, 'billable_kg' => $billable,
                'contractor_rate_id' => $rate->id, 'rate_code' => $rate->code, 'basis' => $rate->basis, 'basis_label' => $rate->basisLabel(),
                'basis_kg' => $basisKg, 'rate_paise' => $rate->rate_paise,
            ],
        ];
    }

    /** @return Builder<GateEntry> */
    private function inPeriod(Party $contractor, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return GateEntry::query()->where('contractor_party_id', $contractor->id)->whereNull('contractor_bill_id')->where('status', '!=', 'cancelled')
            ->where('arrived_at', '>=', $from->startOfDay()->utc())->where('arrived_at', '<=', $to->endOfDay()->utc());
    }

    private function requireRight(): void
    {
        $me = $this->actor->account();
        if (! ($me?->hasPermission('gate.entry') ?? false) && ! ($me?->hasPermission('hr.payroll.run') ?? false)) {
            throw new BusinessRuleViolation("You need 'gate.entry' for this.");
        }
    }
}
