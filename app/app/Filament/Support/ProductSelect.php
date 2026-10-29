<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\Masters\ProductResource;
use App\Models\Masters\Product;
use Filament\Forms\Components\Select;
use Illuminate\Support\HtmlString;

/**
 * The one product picker used by every form: searchable by code or name, active products of
 * the given categories only, labelled "CODE — Name". A record that still points at a product
 * since made inactive keeps its label. When the mill has no such product yet, the field says
 * so and links to Products instead of showing an empty list.
 */
final class ProductSelect
{
    /** @param  list<string>|null  $categories  null = every category */
    public static function make(string $name = 'product_id', ?array $categories = null, bool $stockItemsOnly = false): Select
    {
        return Select::make($name)->label('Product')->searchable()
            ->options(fn () => self::options($categories, $stockItemsOnly))
            ->getOptionLabelUsing(fn ($value) => self::label($value))
            ->helperText(fn () => self::options($categories, $stockItemsOnly) === [] ? self::emptyHint($categories) : null);
    }

    /**
     * @param  list<string>|null  $categories
     * @return array<string, string>
     */
    public static function options(?array $categories = null, bool $stockItemsOnly = false): array
    {
        return Product::query()
            ->where('status', 'active')
            ->when($categories !== null, fn ($q) => $q->whereIn('category', $categories))
            ->when($stockItemsOnly, fn ($q) => $q->where('is_stock_item', true))
            ->orderBy('name')->limit(500)
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Product $p) => [$p->id => self::text($p)])
            ->all();
    }

    public static function label(mixed $id): ?string
    {
        $product = is_string($id) && $id !== '' ? Product::query()->find($id) : null;

        return $product === null ? null : self::text($product).($product->status === 'active' ? '' : ' (inactive)');
    }

    private static function text(Product $p): string
    {
        return $p->code !== '' && $p->code !== null ? $p->code.' — '.$p->name : $p->name;
    }

    /** @param  list<string>|null  $categories */
    private static function emptyHint(?array $categories): HtmlString
    {
        $kind = $categories === null ? 'active products' : 'active '.implode(' / ', array_map(fn ($c) => str_replace('_', '-', $c), $categories)).' products';
        $link = ProductResource::canCreate()
            ? ' <a href="'.e(ProductResource::getUrl('index')).'" class="text-primary-600 underline">Add a product</a>.'
            : ' Ask your administrator to add one under Products.';

        return new HtmlString('This mill has no '.e($kind).' yet.'.$link);
    }
}
