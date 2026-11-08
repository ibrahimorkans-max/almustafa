<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Models\Usage\ServicePrice;
use App\Models\Usage\ServiceUsage;
use Carbon\CarbonInterface;

/**
 * What a use of an outside service costs: TOTAL = QUANTITY × the unit price that applies. The price is the newest one
 * in effect on that day for the company (its own price first, else everyone's), the provider and the service (exact,
 * else any service of the provider, else any provider of the channel). Within the month's free allowance a unit is
 * free; tiers lower the unit price once the month's units pass their start; a fixed package's units are included
 * (its monthly fee is charged on the statement). The company's charge uses the charge price where one is set.
 */
final class Pricing
{
    /** @return array{price_id: ?string, unit_price: ?string, estimated: string, charge: string, currency: string, billing_status: string} */
    public static function cost(?string $customerId, string $channel, string $provider, string $service, int $quantity, CarbonInterface $at): array
    {
        $price = self::priceFor($customerId, $channel, $provider, $service, $at);
        if ($price === null) {
            return ['price_id' => null, 'unit_price' => null, 'estimated' => '0', 'charge' => '0', 'currency' => 'PKR', 'billing_status' => 'unbilled'];
        }
        $usedBefore = (int) ServiceUsage::query()->where('customer_id', $customerId)->where('channel', $channel)
            ->when($price->provider !== '*', fn ($q) => $q->where('provider', $price->provider))
            ->where('success', true)
            ->whereBetween('occurred_at', [$at->copy()->startOfMonth(), $at])->sum('quantity');
        $free = max(0, min($quantity, $price->free_allowance - $usedBefore));
        $billable = $quantity - $free;
        $unit = self::tierPrice($price, $usedBefore + $free + 1);
        $charge = $price->charge_price !== null ? (string) $price->charge_price : $unit;
        $status = match (true) {
            $billable === 0 => 'free',
            $price->model === 'fixed_package' => 'included',
            default => 'unbilled',
        };

        return [
            'price_id' => $price->id, 'unit_price' => $unit,
            'estimated' => bcmul((string) $quantity, $unit, 6),   // what the provider is expected to charge
            'charge' => $price->model === 'fixed_package' ? '0' : bcmul((string) $billable, $charge, 6),
            'currency' => $price->currency, 'billing_status' => $status,
        ];
    }

    public static function priceFor(?string $customerId, string $channel, string $provider, string $service, CarbonInterface $at): ?ServicePrice
    {
        return ServicePrice::query()->where('channel', $channel)
            ->whereDate('effective_from', '<=', $at->toDateString())
            ->where(fn ($q) => $q->whereNull('customer_id')->when($customerId !== null, fn ($q) => $q->orWhere('customer_id', $customerId)))
            ->whereIn('provider', [$provider, '*'])->whereIn('service', [$service, '*'])
            ->get()
            // Most specific first: the company's own, the exact provider, the exact service; then the newest.
            ->sortBy(fn (ServicePrice $p) => [$p->customer_id === null ? 1 : 0, $p->provider === '*' ? 1 : 0, $p->service === '*' ? 1 : 0, -$p->effective_from->timestamp, $p->created_at?->timestamp * -1])
            ->first();
    }

    /** The unit price for the n-th unit of the month (tiers: [{"from": 1001, "price": "0.80"}]). */
    public static function tierPrice(ServicePrice $price, int $n): string
    {
        $unit = (string) $price->unit_price;
        foreach (collect($price->tiers ?? [])->sortBy('from') as $tier) {
            if ($n >= (int) ($tier['from'] ?? PHP_INT_MAX)) {
                $unit = (string) $tier['price'];
            }
        }

        return $unit;
    }
}
