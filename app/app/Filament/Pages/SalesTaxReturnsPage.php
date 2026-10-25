<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tax\SalesTaxReturns;
use App\Http\Controllers\ReportPrintController;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Monthly sales-tax register (Annex-C) and the s. 153 withholding register, from posted documents only. */
class SalesTaxReturnsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Tax registers';

    protected static ?string $title = 'Sales tax and withholding registers';

    protected static ?string $slug = 'tax-registers';

    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.pages.tax-registers';

    #[Url]
    public string $period = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('report.tax');
    }

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period)) {
            $this->period = now('Asia/Karachi')->subMonthNoOverflow()->format('Y-m');
        }
    }

    /** @return array{sales: array<string, mixed>, wht: list<array<string, mixed>>} */
    public function getData(): array
    {
        $returns = app(SalesTaxReturns::class);

        return ['sales' => $returns->salesRegister($this->period), 'wht' => $returns->withholdingRegister($this->period)];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('salesPdf')->label('Annex-C PDF')->icon('heroicon-o-printer')
                ->url(fn () => ReportPrintController::url(['annex_c'], $this->period.'-01', $this->period.'-01'), shouldOpenInNewTab: true),
            Action::make('whtPdf')->label('Withholding PDF')->icon('heroicon-o-printer')->color('gray')
                ->url(fn () => ReportPrintController::url(['wht153'], $this->period.'-01', $this->period.'-01'), shouldOpenInNewTab: true),
            Action::make('allReports')->label('All reports')->icon('heroicon-o-printer')->color('gray')->url(fn () => AllReportsPage::getUrl()),
            Action::make('salesCsv')->label('Annex-C (sales) CSV')->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->download('annex-c-'.$this->period.'.csv', $this->salesCsv())),
            Action::make('whtCsv')->label('Withholding u/s 153 CSV')->icon('heroicon-o-table-cells')->color('gray')
                ->action(fn () => $this->download('wht-153-'.$this->period.'.csv', $this->whtCsv())),
        ];
    }

    private function salesCsv(): string
    {
        $r = app(SalesTaxReturns::class)->salesRegister($this->period);
        $rs = fn (int $p) => number_format($p / 100, 2, '.', '');

        return self::csv(
            ['Buyer NTN / CNIC', 'Buyer name', 'Buyer type', 'Sale origination province', 'Document type', 'Document number', 'Document date',
                'HS code', 'Description', 'Quantity', 'UOM', 'Rate', 'Value of sales excluding sales tax', 'Sales tax', 'Further tax', 'Against invoice'],
            array_map(fn (array $i) => [
                PakistanIds::formatNtn($i['buyer_ntn']), $i['buyer_name'], $i['buyer_type'], $i['province'] ?? 'Punjab', $i['type'], $i['number'], $i['date'],
                $i['pct'], $i['description'], $i['quantity_kg'], 'KG', $i['exempt'] ? 'Exempt' : ($i['rate_bp'] / 100).'%',
                $rs($i['taxable']), $rs($i['sales_tax']), $rs($i['further_tax']), $i['against'],
            ], $r['sales']),
        );
    }

    private function whtCsv(): string
    {
        $rs = fn (int $p) => number_format($p / 100, 2, '.', '');

        return self::csv(
            ['Section', 'Supplier name', 'NTN', 'CNIC', 'Filer (ATL)', 'Bill', 'Date', 'Value of goods', 'Taxable amount', 'Rate', 'Tax withheld'],
            array_map(fn (array $i) => [
                '153(1)(a)', $i['supplier'], PakistanIds::formatNtn($i['ntn']), PakistanIds::formatCnic($i['cnic']), $i['filer'] ? 'Yes' : 'No',
                $i['bill'], $i['date'], $rs($i['gross']), $rs($i['base']), ($i['rate_bp'] / 100).'%', $rs($i['tax']),
            ], app(SalesTaxReturns::class)->withholdingRegister($this->period)),
        );
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<mixed>>  $rows
     */
    private static function csv(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Cannot build the file.');
        fputcsv($out, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($out, $row, escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function download(string $name, string $content): StreamedResponse
    {
        app(AuditLogger::class)->record('report.tax.exported', null, null, null, ['period' => $this->period, 'file' => $name]);

        return response()->streamDownload(fn () => print $content, $name, ['Content-Type' => 'text/csv']);
    }
}
