<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reports\Balances;
use App\Filament\Concerns\TranslatesLabels;
use App\Models\LoginAccount;
use App\Models\Masters\Product;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Stock & production → Stock report: paddy, rice and every other stock item for any dates — opening, in, out and
 * closing, in kg and maund, all godowns together or each one — from the stock ledger.
 */
class StockReportPage extends Page
{
    use TranslatesLabels;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Stock report';

    protected static ?string $title = 'Stock report';

    protected static ?string $slug = 'stock-report';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.stock-report';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $godown = '';

    #[Url(as: 'by_godown')]
    public bool $byGodown = false;

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->hasPermission('stock.view');
    }

    public function mount(): void
    {
        $this->from = $this->date($this->from) ?? now('Asia/Karachi')->startOfMonth()->toDateString();
        $this->to = $this->date($this->to) ?? now('Asia/Karachi')->toDateString();
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $from = $this->date($this->from) ?? now('Asia/Karachi')->startOfMonth()->toDateString();
        $to = $this->date($this->to) ?? now('Asia/Karachi')->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return app(Balances::class)->stock($from, $to, in_array($this->category, Product::CATEGORIES, true) ? $this->category : null,
            preg_match('/^[0-9a-f-]{36}$/i', $this->godown) ? $this->godown : null, $this->byGodown);
    }

    private function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
