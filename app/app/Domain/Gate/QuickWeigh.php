<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Support\Values\PakistanIds;

/**
 * The simple weighing form: vehicle number, party name, the item it carries (a product of the list, or
 * the goods as typed) and what it is (inbound paddy, inbound other goods, outbound). It finds the vehicle's open visit — or registers it, with the next token — so one
 * "Record weight" button can take the weight it needs next (LiveCapture).
 *
 *  - inbound paddy keeps lab first: until the lab accepts the lot, the vehicle waits (no weight);
 *  - inbound other goods and outbound without a dispatch order are weighed at once (purpose "other");
 *  - an outbound vehicle already collecting a dispatch order continues that visit.
 */
final class QuickWeigh
{
    public const KINDS = [
        'paddy' => 'Inbound — paddy (lab first)',
        'other_in' => 'Inbound — other goods',
        'outbound' => 'Outbound',
    ];

    public function __construct(private readonly GateService $gate) {}

    /** The vehicle's open visit, if it has one. */
    public static function openVisit(string $vehicleNumber, ?string $kind = null): ?GateEntry
    {
        $plate = PakistanIds::normalizeVehicleNumber($vehicleNumber);
        if ($plate === '') {
            return null;
        }

        return GateEntry::query()->with('party')->where('registration_no', $plate)
            ->whereNotIn('status', ['exited', 'cancelled'])
            ->when($kind !== null, fn ($q) => $q->where('direction', $kind === 'outbound' ? 'outbound' : 'inbound'))
            ->orderByDesc('arrived_at')->first();
    }

    /**
     * The visit to weigh: the open one, or a new one registered now.
     *
     * @return array{0: GateEntry, 1: bool} the visit, and whether it was registered now
     */
    public function visit(string $partyName, string $vehicleNumber, string $kind, ?string $item = null, ?string $jackType = null): array
    {
        if (! isset(self::KINDS[$kind])) {
            throw new BusinessRuleViolation('Choose inbound (paddy or other goods) or outbound.');
        }
        $plate = PakistanIds::normalizeVehicleNumber($vehicleNumber);
        if (! PakistanIds::isValidVehicleNumber($plate)) {
            throw new BusinessRuleViolation('Type the vehicle number, e.g. MNA-21-1234.');
        }
        [$productId, $goods] = self::item((string) $item);
        $open = self::openVisit($plate, $kind);
        if ($open !== null) {
            // The item typed now completes a visit registered without one.
            if ($open->product_id === null && $open->goods_description === null && ($productId !== null || $goods !== null)) {
                $open->forceFill(['product_id' => $productId, 'goods_description' => $goods])->save();
            }

            return [$open, false];
        }

        // No party record is made here: an unregistered name is kept on the visit as typed.
        $entry = $this->gate->arrive(array_filter([
            'registration_no' => $plate,
            'direction' => $kind === 'outbound' ? 'outbound' : 'inbound',
            'purpose' => $kind === 'paddy' ? 'paddy_purchase' : 'other',
            'party_name' => self::partyName($partyName),
            'product_id' => $productId,
            'goods_description' => $goods,
            'jack_type' => $jackType,
        ], fn ($v) => $v !== null));

        return [$entry, true];
    }

    /**
     * The item typed: a product of the list (by name or code) or, if none matches, the goods as typed.
     *
     * @return array{0: string|null, 1: string|null} product id, goods description
     */
    public static function item(string $item): array
    {
        $item = trim(preg_replace('/\s+/', ' ', $item) ?? '');
        if ($item === '') {
            return [null, null];
        }
        $product = Product::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($item)])
            ->orWhereRaw('LOWER(code) = ?', [mb_strtolower($item)])->first();

        return $product !== null ? [(string) $product->id, null] : [null, mb_substr($item, 0, 160)];
    }

    /** The party as typed: a registered party when the name (or "Name (CODE)") matches one, else kept as typed. */
    private static function partyName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if (mb_strlen($name) < 2) {
            throw new BusinessRuleViolation('Type the party name.');
        }

        return $name;
    }
}
