<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Models\Inventory\StockBalance;
use App\Models\LoginAccount;

/** Stock in hand by product and godown, optionally for one product. */
final class StockTool extends Tool
{
    public function name(): string
    {
        return 'stock_balance';
    }

    public function description(): string
    {
        return 'Stock in hand (kg and maund) by product and godown; optionally only products whose name contains the given words.';
    }

    public function properties(): array
    {
        return ['product' => ['type' => 'string', 'description' => 'Part of the product name, e.g. "1121" or "bran".']];
    }

    public function permission(): string
    {
        return 'stock.view';
    }

    public function patterns(): array
    {
        return ['/\b(stock|maal|godown)\b(?:\s+(?:of|for))?\s*(?<product>[\w\- ]{2,40})?$/i'];
    }

    public function examples(): array
    {
        return ['stock', 'stock of 1121'];
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $q = trim((string) ($input['product'] ?? ''));
        $rows = StockBalance::query()->with(['product', 'godown'])->where('quantity_kg', '<>', 0)
            ->when($q !== '', fn ($query) => $query->whereHas('product', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($q).'%'])))
            ->get()->sortBy(fn (StockBalance $b) => $b->product->name)
            ->map(fn (StockBalance $b) => [$b->product->name, $b->godown->name, number_format((float) $b->quantity_kg, 0).' kg', number_format((float) $b->quantity_kg / 40, 1).' maund'])
            ->values()->all();

        return $rows === [] ? new ToolResult($q === '' ? 'No stock in hand.' : "No stock of \"{$q}\".")
            : new ToolResult('Stock in hand'.($q !== '' ? " for \"{$q}\"" : '').':', table: ['columns' => ['Product', 'Godown', 'kg', 'Maund'], 'rows' => $rows]);
    }
}
