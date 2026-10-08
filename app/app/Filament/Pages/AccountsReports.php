<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Accounts\AccountReports;
use App\Domain\Accounts\StockValuation;
use App\Domain\Audit\AuditLogger;
use App\Models\Accounts\LedgerAccount;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use Barryvdh\DomPDF\Facade\Pdf;
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

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Books & reports';

    protected static ?string $title = 'Books & reports';

    protected static ?string $slug = 'accounts-reports';

    protected static ?int $navigationSort = 70;

    protected static string $view = 'filament.pages.accounts-reports';

    /** @var array<string, string> */
    public const REPORTS = [
        'trial_balance' => 'Trial balance', 'ledger' => 'Ledger statement', 'day_book' => 'Day book',
        'ageing' => 'Payables ageing', 'stock_value' => 'Stock value',
    ];

    #[Url]
    public string $report = 'trial_balance';

    #[Url]
    public ?string $ledger = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('ledger.view');
    }

    public function mount(): void
    {
        $today = now('Asia/Kolkata');
        $this->form->fill([
            'report' => isset(self::REPORTS[$this->report]) ? $this->report : 'trial_balance',
            'ledger' => $this->ledger,
            'from' => $today->copy()->startOfMonth()->toDateString(),
            'to' => $today->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->columns(4)->statePath('data')->schema([
            Select::make('report')->options(self::REPORTS)->required()->live()->selectablePlaceholder(false),
            Select::make('ledger')->label('Ledger')->searchable()->live()
                ->options(fn () => LedgerAccount::query()->orderBy('name')->get()->mapWithKeys(fn (LedgerAccount $l) => [$l->id => $l->displayName()]))
                ->visible(fn (Get $get) => $get('report') === 'ledger'),
            DatePicker::make('from')->live()->visible(fn (Get $get) => $get('report') === 'ledger'),
            DatePicker::make('to')->label(fn (Get $get) => in_array($get('report'), ['ledger'], true) ? 'To' : 'As on / date')->live()
                ->visible(fn (Get $get) => $get('report') !== 'stock_value'),
        ]);
    }

    /** @return array<string, mixed> */
    public function getReportData(): array
    {
        $reports = app(AccountReports::class);
        $to = CarbonImmutable::parse($this->data['to'] ?? now('Asia/Kolkata')->toDateString(), 'Asia/Kolkata');

        return match ($this->data['report'] ?? 'trial_balance') {
            'ledger' => ($id = $this->data['ledger'] ?? null) && ($account = LedgerAccount::query()->find($id))
                ? ['account' => $account, 'statement' => $reports->ledgerStatement($account, CarbonImmutable::parse($this->data['from'], 'Asia/Kolkata'), $to)]
                : ['account' => null],
            'day_book' => ['vouchers' => $reports->dayBook($to)],
            'ageing' => ['rows' => $reports->payablesAgeing($to)],
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
            Action::make('pdf')->label('Ledger statement PDF')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => ($this->data['report'] ?? null) === 'ledger' && filled($this->data['ledger'] ?? null))
                ->action(fn () => $this->statementPdf()),
        ];
    }

    private function statementPdf(): ?StreamedResponse
    {
        $account = LedgerAccount::query()->find($this->data['ledger'] ?? null);
        if ($account === null) {
            return null;
        }
        $from = CarbonImmutable::parse($this->data['from'], 'Asia/Kolkata');
        $to = CarbonImmutable::parse($this->data['to'], 'Asia/Kolkata');
        $statement = app(AccountReports::class)->ledgerStatement($account, $from, $to);
        app(AuditLogger::class)->record('report.ledger_statement.downloaded', $account, meta: ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
        $pdf = Pdf::loadView('reports.ledger-statement', compact('account', 'statement', 'from', 'to'))->output();

        return response()->streamDownload(fn () => print $pdf, "statement-{$account->code}.pdf", ['Content-Type' => 'application/pdf']);
    }
}
