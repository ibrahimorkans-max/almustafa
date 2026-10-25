<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Reports\BusinessReport;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Support\Export\XlsxWorkbook;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The complete business report in one click (no audit log): pick the period, see the key figures,
 * download it as a PDF or an Excel workbook with one sheet per part.
 */
class BusinessReportPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Complete business report';

    protected static ?string $title = 'Complete business report';

    protected static ?string $slug = 'business-report';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.business-report';

    public string $period = 'this_month';

    public ?string $from = null;

    public ?string $to = null;

    public const PERIODS = [
        'today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'this_month' => 'This month',
        'last_month' => 'Last month', 'this_year' => 'This financial year (July–June)', 'custom' => 'Choose dates',
    ];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('report.view') && $user->hasPermission('ledger.view');
    }

    public function millChosen(): bool
    {
        return app(TenantContext::class)->entityId() !== null;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(): array
    {
        $today = CarbonImmutable::now('Asia/Karachi')->startOfDay();

        return match ($this->period) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'this_week' => [$today->startOfWeek(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_year' => [$today->month >= 7 ? $today->setDate($today->year, 7, 1) : $today->setDate($today->year - 1, 7, 1), $today],
            'custom' => [
                CarbonImmutable::parse($this->from ?: $today->toDateString(), 'Asia/Karachi')->startOfDay(),
                CarbonImmutable::parse($this->to ?: $today->toDateString(), 'Asia/Karachi')->startOfDay(),
            ],
            default => [$today->startOfMonth(), $today],
        };
    }

    /** @return array{from: string, to: string, generated_at: string, headline: list<array{0: string, 1: string}>, sections: list<array<string, mixed>>}|null */
    public function report(): ?array
    {
        if (! $this->millChosen()) {
            return null;
        }
        [$from, $to] = $this->range();
        if ($to->lessThan($from)) {
            return null;
        }

        return app(BusinessReport::class)->build($from, $to);
    }

    public function switchUrl(): string
    {
        return SwitchContext::getUrl();
    }

    public function downloadPdf(): ?StreamedResponse
    {
        return $this->download('pdf');
    }

    public function downloadExcel(): ?StreamedResponse
    {
        return $this->download('xlsx');
    }

    private function download(string $format): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $report = $this->report();
        if ($report === null) {
            Notification::make()->title($this->millChosen() ? 'The end date is before the start date.' : 'Choose the mill first.')->warning()->send();

            return null;
        }
        $name = Str::slug('business report '.$report['from'].' '.$report['to']);
        app(AuditLogger::class)->record('report.business.downloaded', null, null, null, ['from' => $report['from'], 'to' => $report['to'], 'format' => $format]);

        if ($format === 'xlsx') {
            $book = new XlsxWorkbook;
            $book->addSheet('Summary', ['Figure', 'Value'], array_map(fn (array $r) => [$r[0], $r[1]], $report['headline']));
            foreach ($report['sections'] as $s) {
                $rows = array_map(fn (array $row) => array_map(
                    fn ($v, $i) => in_array($i, $s['money'], true) && is_int($v) ? round($v / 100, 2) : $v,
                    $row, array_keys($row),
                ), $s['rows']);
                $book->addSheet($s['title'], $s['columns'], $rows, $s['money']);
            }
            $bytes = $book->toString();

            return response()->streamDownload(fn () => print $bytes, "{$name}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        $entity = Entity::query()->find(app(TenantContext::class)->entityId());
        $pdf = app(PdfRenderer::class)->render('reports.business', ['report' => $report], $entity, 'A4-L');

        return response()->streamDownload(fn () => print $pdf, "{$name}.pdf", ['Content-Type' => 'application/pdf']);
    }
}
