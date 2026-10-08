<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Gst\GstReturns;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GSTR-1 tables, HSN summary and GSTR-3B totals for a month, from posted documents only. */
class GstReturnsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'GST returns';

    protected static ?string $title = 'GST returns';

    protected static ?string $slug = 'gst-returns';

    protected static ?int $navigationSort = 75;

    protected static string $view = 'filament.pages.gst-returns';

    #[Url]
    public string $period = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('report.gst');
    }

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period)) {
            $this->period = now('Asia/Kolkata')->subMonthNoOverflow()->format('Y-m');
        }
    }

    /** @return array{r1: array<string, mixed>, r3b: array<string, mixed>} */
    public function getData(): array
    {
        $returns = app(GstReturns::class);

        return ['r1' => $returns->gstr1($this->period), 'r3b' => $returns->gstr3b($this->period)];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('json')->label('GSTR-1 JSON')->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->download('gstr1-'.$this->period.'.json', (string) json_encode(app(GstReturns::class)->gstr1Json($this->period), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'application/json')),
            Action::make('csv')->label('GSTR-1 CSV (all tables)')->icon('heroicon-o-table-cells')->color('gray')
                ->action(fn () => $this->download('gstr1-'.$this->period.'.csv', $this->csv(), 'text/csv')),
        ];
    }

    private function csv(): string
    {
        $r = app(GstReturns::class)->gstr1($this->period);
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Cannot build the file.');
        $rs = fn (int $p) => number_format($p / 100, 2, '.', '');
        fputcsv($out, ['Table', 'GSTIN / supply', 'Number', 'Date', 'Place of supply', 'Rate %', 'Taxable', 'IGST', 'CGST', 'SGST', 'Value / original'], escape: '');
        foreach ($r['b2b'] as $i) {
            fputcsv($out, ['B2B', $i['ctin'], $i['number'], $i['date'], $i['pos'], $i['rate_bp'] / 100, $rs($i['taxable']), $rs($i['igst']), $rs($i['cgst']), $rs($i['sgst']), $rs($i['value'])], escape: '');
        }
        foreach ($r['b2cs'] as $i) {
            fputcsv($out, ['B2CS', $i['supply'], '', '', $i['pos'], $i['rate_bp'] / 100, $rs($i['taxable']), $rs($i['igst']), $rs($i['cgst']), $rs($i['sgst']), ''], escape: '');
        }
        foreach ($r['cdnr'] as $i) {
            fputcsv($out, ['CDNR', $i['ctin'], $i['number'], $i['date'], $i['pos'], $i['rate_bp'] / 100, $rs($i['taxable']), $rs($i['igst']), $rs($i['cgst']), $rs($i['sgst']), $i['original_invoice']], escape: '');
        }
        foreach ($r['hsn'] as $h) {
            fputcsv($out, ['HSN', $h['hsn'], $h['description'], $h['uqc'], $h['quantity_kg'], $h['rate_bp'] / 100, $rs($h['taxable']), $rs($h['igst']), $rs($h['cgst']), $rs($h['sgst']), ''], escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function download(string $name, string $content, string $type): StreamedResponse
    {
        app(AuditLogger::class)->record('report.gst.exported', null, null, null, ['period' => $this->period, 'file' => $name]);

        return response()->streamDownload(fn () => print $content, $name, ['Content-Type' => $type]);
    }
}
