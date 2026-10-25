<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reports\ReportCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\ReportPrintController;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Every report the person may see, by category: pick the period and the reports (a whole category
 * with one tick), then print them all as one PDF (contents page, each report from a new page) or one
 * Excel workbook (a sheet per report).
 */
class AllReportsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-printer';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'All reports (print in one click)';

    protected static ?string $title = 'All reports';

    protected static ?string $slug = 'all-reports';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.all-reports';

    public string $period = 'this_month';

    public ?string $from = null;

    public ?string $to = null;

    /** @var list<string> the ticked reports */
    public array $selected = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ReportCatalog::forUser($user) !== [];
    }

    public function mount(): void
    {
        $this->selected = array_keys(array_merge(...array_values($this->catalog())));
    }

    /** @return array<string, array<string, string>> category => [key => title] */
    public function catalog(): array
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount ? ReportCatalog::forUser($user) : [];
    }

    public function millChosen(): bool
    {
        return app(TenantContext::class)->entityId() !== null;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(): array
    {
        $page = new BusinessReportPage;
        $page->period = $this->period;
        $page->from = $this->from;
        $page->to = $this->to;

        return $page->range();
    }

    public function toggleCategory(string $category): void
    {
        $keys = array_keys($this->catalog()[$category] ?? []);
        $this->selected = array_values(array_diff($keys, $this->selected) === []
            ? array_diff($this->selected, $keys)
            : array_unique([...$this->selected, ...$keys]));
    }

    public function selectAll(bool $all): void
    {
        $this->selected = $all ? array_keys(array_merge(...array_values($this->catalog()))) : [];
    }

    /** The print link for the ticked reports (or one category / one report), null when nothing is ticked or the dates are wrong. */
    public function printUrl(?string $only = null, string $format = 'pdf', ?string $category = null): ?string
    {
        $allowed = array_merge(...array_values($this->catalog() ?: [[]]));
        $keys = match (true) {
            $only !== null => array_key_exists($only, $allowed) ? [$only] : [],
            $category !== null => array_keys($this->catalog()[$category] ?? []),
            default => array_values(array_intersect(array_keys($allowed), $this->selected)),
        };
        [$from, $to] = $this->range();
        if ($keys === [] || $to->lessThan($from)) {
            return null;
        }

        return ReportPrintController::url($keys, $from->toDateString(), $to->toDateString(), $format === 'xlsx' ? ['format' => 'xlsx'] : []);
    }
}
