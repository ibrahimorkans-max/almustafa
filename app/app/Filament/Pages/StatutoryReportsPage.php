<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Hr\Payroll\StatutoryReports;
use App\Domain\Shared\BusinessRuleViolation;
use App\Http\Controllers\ReportPrintController;
use App\Models\LoginAccount;
use App\Support\Export\XlsxWriter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** EOBI, PESSI, s. 149 salary tax, wages and overtime registers of a finalized month; CSV or Excel. */
class StatutoryReportsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Statutory reports';

    protected static ?string $title = 'Payroll statutory reports';

    protected static ?string $slug = 'hr/statutory-reports';

    protected static ?int $navigationSort = 70;

    protected static string $view = 'filament.pages.statutory-reports';

    #[Url]
    public string $month = '';

    #[Url]
    public string $report = 'wages';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->hasPermission('hr.payroll.run') || $user->hasPermission('hr.payroll.approve'));
    }

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            $this->month = now('Asia/Karachi')->subMonthNoOverflow()->format('Y-m');
        }
        if (! array_key_exists($this->report, StatutoryReports::REPORTS)) {
            $this->report = 'wages';
        }
    }

    /** @return array{title: string, header: list<string>, rows: list<list<string|int|float|null>>}|string the report, or why it cannot be made */
    public function getData(): array|string
    {
        try {
            return app(StatutoryReports::class)->build($this->report, $this->month);
        } catch (BusinessRuleViolation $e) {
            return $e->getMessage();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')->label('PDF')->icon('heroicon-o-printer')
                ->url(fn () => ReportPrintController::url([$this->report], $this->month.'-01', $this->month.'-01'), shouldOpenInNewTab: true),
            Action::make('allReports')->label('All reports')->icon('heroicon-o-printer')->color('gray')->url(fn () => AllReportsPage::getUrl()),
            Action::make('xlsx')->label('Excel')->icon('heroicon-o-table-cells')->action(fn () => $this->download('xlsx')),
            Action::make('csv')->label('CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')->action(fn () => $this->download('csv')),
        ];
    }

    private function download(string $format): ?StreamedResponse
    {
        $data = $this->getData();
        if (is_string($data)) {
            Notification::make()->title($data)->danger()->send();

            return null;
        }
        $name = "{$this->report}-{$this->month}.{$format}";
        app(AuditLogger::class)->record('hr.payroll.report_exported', null, null, null, ['report' => $this->report, 'month' => $this->month, 'file' => $name, 'rows' => count($data['rows'])]);
        if ($format === 'xlsx') {
            $bytes = XlsxWriter::build($this->report.' '.$this->month, $data['header'], $data['rows']);

            return response()->streamDownload(fn () => print $bytes, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Cannot build the file.');
        fputcsv($out, $data['header'], escape: '');
        foreach ($data['rows'] as $row) {
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row), escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return response()->streamDownload(fn () => print $csv, $name, ['Content-Type' => 'text/csv']);
    }
}
