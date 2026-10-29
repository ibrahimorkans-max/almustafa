<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Reports\FinancialTables;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Support\ExportGuard;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Support\Export\XlsxWriter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profit and loss and the management reports, from posted vouchers only. Each amount line opens the
 * ledger statement behind it; every report downloads as PDF or Excel (downloads are audited).
 *
 * @property Form $form
 */
class FinancialReportsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Profit & loss';

    protected static ?string $title = 'Profit & loss and management reports';

    protected static ?string $slug = 'profit-and-loss';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.financial-reports';

    #[Url]
    public string $report = 'pl';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('ledger.view');
    }

    public function mount(): void
    {
        $today = now('Asia/Karachi');
        $this->form->fill([
            'report' => isset(FinancialTables::REPORTS[$this->report]) ? $this->report : 'pl',
            'from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->toDateString(),
            'month' => $today->format('Y-m'),
        ]);
    }

    public function form(Form $form): Form
    {
        $uses = fn (string ...$reports) => fn (Get $get) => in_array($get('report'), $reports, true);

        return $form->columns(4)->statePath('data')->schema([
            Select::make('report')->options(FinancialTables::REPORTS)->required()->live()->selectablePlaceholder(false)
                ->afterStateUpdated(fn (?string $state) => $this->report = (string) $state),
            DatePicker::make('from')->live()->required()->visible($uses('pl', 'monthly', 'payroll', 'cashflow')),
            DatePicker::make('to')->label(fn (Get $get) => $get('report') === 'staff' ? 'As on' : 'To')->live()->required()
                ->visible($uses('pl', 'monthly', 'payroll', 'cashflow', 'staff')),
            TextInput::make('month')->live(onBlur: true)->regex('/^\d{4}-(0[1-9]|1[0-2])$/')->placeholder('2026-10')->visible($uses('budget')),
        ]);
    }

    /** @return array{title: string, subtitle: string, columns: list<string>, rows: list<array<string, mixed>>, notes: list<string>} */
    public function getTable(): array
    {
        return app(FinancialTables::class)->build((string) ($this->data['report'] ?? 'pl'), [
            'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'month' => $this->data['month'] ?? null,
        ]);
    }

    /** The ledger statement behind a line, for the same period. */
    public function ledgerUrl(string $ledgerId): string
    {
        $report = $this->data['report'] ?? 'pl';
        [$from, $to] = $report === 'budget' && filled($this->data['month'] ?? null)
            ? [$this->data['month'].'-01', now('Asia/Karachi')->parse($this->data['month'].'-01')->endOfMonth()->toDateString()]
            : [$this->data['from'] ?? null, $this->data['to'] ?? null];

        return AccountsReports::getUrl(['report' => 'ledger', 'ledger' => $ledgerId, 'from' => $from, 'to' => $to]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('allReports')->label('All reports')->icon('heroicon-o-printer')->color('gray')->url(fn () => AllReportsPage::getUrl()),
            Action::make('ytd')->label('This tax year')->color('gray')->icon('heroicon-o-calendar')
                ->visible(fn () => in_array($this->data['report'] ?? 'pl', ['pl', 'monthly', 'payroll', 'cashflow'], true))
                ->action(fn () => $this->data = ['from' => self::taxYearStart($this->data['to'] ?? null)] + ($this->data ?? [])),
            Action::make('pdf')->visible(fn () => ExportGuard::allowed())->label('PDF')->icon('heroicon-o-arrow-down-tray')->action(fn () => $this->download('pdf')),
            Action::make('excel')->visible(fn () => ExportGuard::allowed())->label('Excel')->icon('heroicon-o-table-cells')->color('gray')->action(fn () => $this->download('xlsx')),
        ];
    }

    /** The Pakistani tax year runs from 1 July to 30 June. */
    public static function taxYearStart(?string $date): string
    {
        $d = now('Asia/Karachi')->parse($date ?? now('Asia/Karachi')->toDateString());

        return ($d->month >= 7 ? $d->setDate($d->year, 7, 1) : $d->setDate($d->year - 1, 7, 1))->toDateString();
    }

    private function download(string $format): StreamedResponse
    {
        $t = $this->getTable();
        $name = Str::slug($t['title'].' '.$t['subtitle']);
        app(AuditLogger::class)->record('report.financial.downloaded', null, null, null, ['report' => $this->data['report'] ?? 'pl', 'format' => $format, 'subtitle' => $t['subtitle']]);
        if ($format === 'xlsx') {
            $rows = [];
            foreach ($t['rows'] as $r) {
                $rows[] = [$r['style'] === 'line' ? '   '.$r['label'] : $r['label'], ...array_map(fn ($c) => is_int($c) ? round($c / 100, 2) : $c, $r['cells'])];
            }
            $bytes = XlsxWriter::build(mb_substr($t['title'], 0, 31), [$t['columns'][0] === '' ? $t['title'] : $t['columns'][0], ...array_slice($t['columns'], 1)], $rows);

            return response()->streamDownload(fn () => print $bytes, "{$name}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }
        // The letterhead is the working mill's (or, outside a request's tenant scope, the user's own).
        $tenant = app(TenantContext::class);
        $user = Filament::auth()->user();
        $entityId = $tenant->entityId() ?? ($user instanceof LoginAccount ? $user->entity_id : null);
        $entity = $tenant->runAsSystem(fn () => Entity::query()->find($entityId));
        $pdf = app(PdfRenderer::class)->render('reports.financial', ['table' => $t, 'title' => $t['title']], $entity);

        return response()->streamDownload(fn () => print $pdf, "{$name}.pdf", ['Content-Type' => 'application/pdf']);
    }
}
