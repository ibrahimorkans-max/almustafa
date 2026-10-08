<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Accounts\StockValuation;
use App\Domain\Approvals\Actions\RateChange;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use App\Models\Masters\Product;
use App\Models\Pricing\MarkupSetting;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Selling rates and confidential cost / margin.
 *
 * * Rates live in price lists as append-only versions; a change is requested (`pricing.rate.edit`) and
 *   applied only after a different person approves it.
 * * Cost, margin and markup are confidential: `margin()` returns null for a viewer without
 *   `pricing.margin.view`. Figures are hidden, never adjusted — whoever may see them sees the real ones.
 */
final class PricingService
{
    public function __construct(
        private readonly StockValuation $valuation,
        private readonly ActorContext $actor,
    ) {}

    public function createList(string $name, CarbonInterface $validFrom, ?CarbonInterface $validTo = null): PriceList
    {
        $this->require('pricing.rate.edit');
        if ($validTo !== null && $validTo->lessThan($validFrom)) {
            throw new BusinessRuleViolation('The list must end after it starts.');
        }

        return PriceList::query()->create(['name' => $name, 'valid_from' => $validFrom->toDateString(), 'valid_to' => $validTo?->toDateString()]);
    }

    public function requestRate(PriceList $list, Product $product, int $ratePaisePerQuintal, string $reason): ApprovalRequest
    {
        $this->require('pricing.rate.edit');
        if ($ratePaisePerQuintal <= 0) {
            throw new BusinessRuleViolation('The rate must be more than zero.');
        }
        $current = $this->currentItem($list, $product);

        return app(ApprovalService::class)->submit(RateChange::KEY, [
            'price_list_id' => $list->id, 'product_id' => $product->id, 'rate_paise_per_quintal' => $ratePaisePerQuintal, 'reason' => $reason,
        ], sprintf('Rate %s: %s → %s per qtl', $product->name, $current ? Money::paise($current->rate_paise_per_quintal)->format() : 'new',
            Money::paise($ratePaisePerQuintal)->format()), $reason);
    }

    /** Called only by an approved RateChange. */
    public function applyRate(PriceList $list, Product $product, int $ratePaisePerQuintal, string $reason, string $approvalRequestId): PriceListItem
    {
        return DB::transaction(function () use ($list, $product, $ratePaisePerQuintal, $reason, $approvalRequestId): PriceListItem {
            PriceList::query()->whereKey($list->id)->lockForUpdate()->first();
            $current = $this->currentItem($list, $product);

            return PriceListItem::query()->create([
                'entity_id' => $list->entity_id, 'price_list_id' => $list->id, 'product_id' => $product->id,
                'rate_paise_per_quintal' => $ratePaisePerQuintal, 'supersedes_id' => $current?->id,
                'approval_request_id' => $approvalRequestId, 'reason' => $reason, 'created_at' => now(),
            ]);
        });
    }

    public function currentItem(PriceList $list, Product $product): ?PriceListItem
    {
        return PriceListItem::query()->where('price_list_id', $list->id)->where('product_id', $product->id)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /** List rate for a product on a date (the active list valid that day), in paise per quintal. */
    public function listRate(Product $product, ?CarbonInterface $on = null): ?int
    {
        $day = CarbonImmutable::instance($on ?? now())->setTimezone('Asia/Kolkata')->toDateString();
        $list = PriceList::query()->where('status', 'active')->where('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $day))
            ->orderByDesc('valid_from')->first();

        return $list ? $this->currentItem($list, $product)?->rate_paise_per_quintal : null;
    }

    public function setMarkup(string $category, int $markupBp): MarkupSetting
    {
        $this->require('pricing.markup.set');
        if (! in_array($category, Product::CATEGORIES, true) || $markupBp < 0 || $markupBp > 100000) {
            throw new BusinessRuleViolation('Choose a product category and a markup between 0 and 1000 %.');
        }
        $setting = MarkupSetting::query()->firstOrNew(['category' => $category]);
        $setting->markup_bp = $markupBp;
        $setting->save();

        return $setting;
    }

    /**
     * Cost and margin for one selling rate — or null when the viewer may not see them.
     *
     * @return array{cost_paise_per_quintal: int, margin_paise_per_quintal: int, margin_bp: int|null, suggested_paise_per_quintal: int|null}|null
     */
    public function margin(?LoginAccount $viewer, Product $product, int $ratePaisePerQuintal): ?array
    {
        if ($viewer === null || ! $viewer->hasPermission('pricing.margin.view')) {
            return null;
        }
        $cost = (int) bcadd(bcmul($this->valuation->averageOf($product->id), '100', 4), '0.5', 0);
        $markup = MarkupSetting::query()->where('category', $product->category)->value('markup_bp');

        return [
            'cost_paise_per_quintal' => $cost,
            'margin_paise_per_quintal' => $ratePaisePerQuintal - $cost,
            'margin_bp' => $ratePaisePerQuintal > 0 ? intdiv(($ratePaisePerQuintal - $cost) * 10000, $ratePaisePerQuintal) : null,
            'suggested_paise_per_quintal' => $markup === null || $cost === 0 ? null : intdiv($cost * (10000 + (int) $markup) + 5000, 10000),
        ];
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}
