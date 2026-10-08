<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Pricing\PricingService;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use App\Models\Masters\Product;
use App\Models\Pricing\PriceList;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A new selling rate in a price list: requested with `pricing.rate.edit`, applied after a different person approves. */
final class RateChange implements ApprovalAction
{
    public const KEY = 'pricing.rate_change';

    public function __construct(private readonly PricingService $pricing) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'pricing.rate.edit';
    }

    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        return $checker->hasPermission('pricing.view');
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $data = Validator::make($payload, [
            'price_list_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'rate_paise_per_quintal' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ])->validate();
        if (! PriceList::query()->whereKey($data['price_list_id'])->exists() || ! Product::query()->whereKey($data['product_id'])->exists()) {
            throw ValidationException::withMessages(['price_list_id' => 'Price list or product not found.']);
        }

        return $data;
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        $p = $request->payload;
        $list = PriceList::query()->find($p['price_list_id']) ?? throw new ApprovalDenied('Price list not found.');
        $product = Product::query()->find($p['product_id']) ?? throw new ApprovalDenied('Product not found.');
        $item = $this->pricing->applyRate($list, $product, (int) $p['rate_paise_per_quintal'], (string) $p['reason'], $request->id);

        return ['price_list_item_id' => $item->id];
    }
}
