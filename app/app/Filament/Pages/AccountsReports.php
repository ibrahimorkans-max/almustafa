<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Accounts\AccountReports;
use App\Domain\Accounts\StockValuation;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Reports\CashBankBooks;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Support\ExportGuard;
use App\Http\Controllers\ReportPrintController;
use App\Models\Accounts\LedgerAccount;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Books and statements, read straight from voucher lines.
 *
 * @property Form $form
 */
class AccountsReports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Books & reports';

    protected static ?string $title = 'Books & reports';

    protected static ?string $slug = 'accounts-reports';

    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.pages.accounts-reports';

    /** @var array<string, string> */
    public const REPORTS = [
        'trial_balance' => 'Trial balance', 'ledger' => 'Ledger statement', 'day_book' => 'Day book',
        'ageing' => 'Payables ageing', 'receivables' => 'Receivables ageing', 'stock_value' => 'Stock value',
        'cashbook' => 'Cash book', 'bankbook' => 'Bank book',
    ];

    /** Reports over a period (From – To); the rest are as on one date. */
    public const RANGED = ['ledger', 'cashbook', 'bankbook'];

    /** this screen's report => App\Domain\Reports\ReportCatalog key (for its PDF) */
    public const CATALOG_KEYS = ['trial_balance' => 'trial_balance', 'day_book' => 'day_book', 'ageing' => 'payables', 'receivables' => 'receivables',
        'stock_value' => 'stock_value', 'cashbook' => 'cashbook', 'bankbook' => 'bankbook'];

    #[Url]
    public string $report = 'trial_balance';

    #[Url]
    public ?string $ledger = null;

    /** Period of a ledger statement opened from another report (Profit & loss drill-down). */
    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

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
            'report' => isset(self::REPORTS[$this->report]) ? $this->report : 'trial_balance',
            'ledger' => $this->ledger,
            'from' => self::date($this->from) ?? $today->copy()->startOfMonth()->toDateString(),
            'to' => self::date($this->to) ?? $today->toDateString(),
        ]);
    }

    private static function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    public function form(Form $form): Form
    {
        return $form->columns(4)->statePath('data')->schema([
            Select::make('report')->options(self::REPORTS)->required()->live()->selectablePlaceholder(false),
            Select::make('ledger')->label('Ledger')->searchable()->live()
                ->options(fn () => LedgerAccount::query()->orderBy('name')->get()->mapWithKeys(fn (LedgerAccount $l) => [$l->id => $l->displayName()]))
                ->visible(fn (Get $get) => $get('report') === 'ledger'),
            DatePicker::make('from')->live()->visible(fn (Get $get) => in_array($get('report'), self::RANGED, true)),
            DatePicker::make('to')->label(fn (Get $get) => in_array($get('report'), self::RANGED, true) ? 'To' : 'As on / date')->live()
                ->visible(fn (Get $get) => $get('report') !== 'stock_value'),
        ]);
    }

    /** @return array<string, mixed> */
    public function getReportData(): array
    {
        $reports = app(AccountReports::class);
        $to = CarbonImmutable::parse($this->data['to'] ?? now('Asia/Karachi')->toDateString(), 'Asia/Karachi');

        return match ($this->data['report'] ?? 'trial_balance') {
            'ledger' => ($id = $this->data['ledger'] ?? null) && ($account = LedgerAccount::query()->find($id))
                ? ['account' => $account, 'statement' => $reports->ledgerStatement($account, CarbonImmutable::parse($this->data['from'], 'Asia/Karachi'), $to)]
                : ['account' => null],
            'day_book' => ['vouchers' => $reports->dayBook($to)],
            'cashbook', 'bankbook' => ['parts' => app(CashBankBooks::class)->build($this->data['report'] === 'cashbook' ? 'cash' : 'bank',
                CarbonImmutable::parse($this->data['from'] ?? $to->startOfMonth()->toDateString(), 'Asia/Karachi'), $to)],
            'ageing' => ['rows' => $reports->payablesAgeing($to)],
            'receivables' => ['rows' => $reports->receivablesAgeing($to)],
            'stock_value' => [
                'rows' => app(StockValuation::class)->stockValue(),
                'products' => Product::query()->pluck('name', 'id'),
                'godowns' => Godown::query()->pluck('name', 'id'),
            ],
            default => ['tb' => $reports->trialBalance($to)],
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reportPdf')->label('PDF')->icon('heroicon-o-printer')
                ->visible(fn () => ($this->data['report'] ?? 'trial_balance') !== 'ledger')
                ->url(fn () => ReportPrintController::url([self::CATALOG_KEYS[$this->data['report'] ?? 'trial_balance'] ?? 'trial_balance'],
                    in_array($this->data['report'] ?? null, self::RANGED, true) ? ($this->data['from'] ?? null) : ($this->data['to'] ?? null), $this->data['to'] ?? null), shouldOpenInNewTab: true),
            Action::make('allReports')->label('All reports')->icon('heroicon-o-printer')->color('gray')->url(fn () => AllReportsPage::getUrl()),
            Action::make('pdf')->label('Ledger statement PDF')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => ExportGuard::allowed() && ($this->data['report'] ?? null) === 'ledger' && filled($this->data['ledger'] ?? null))
                ->action(fn () => $this->statementPdf()),
        ];
    }

    private function statementPdf(): ?StreamedResponse
    {
        $account = LedgerAccount::query()->find($this->data['ledger'] ?? null);
        if ($account === null) {
            return null;
        }
        $from = CarbonImmutable::parse($this->data['from'], 'Asia/Karachi');
        $to = CarbonImmutable::parse($this->data['to'], 'Asia/Karachi');
        $statement = app(AccountReports::class)->ledgerStatement($account, $from, $to);
        app(AuditLogger::class)->record('report.ledger_statement.downloaded', $account, meta: ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
        $entity = app(TenantContext::class)->runAsSystem(fn () => Entity::query()->find($account->entity_id));
        $pdf = app(PdfRenderer::class)->render('reports.ledger-statement', ['title' => 'Statement '.$account->displayName()] + compact('account', 'statement', 'from', 'to'), $entity);

        return response()->streamDownload(fn () => print $pdf, "statement-{$account->code}.pdf", ['Content-Type' => 'application/pdf']);
    }
}
