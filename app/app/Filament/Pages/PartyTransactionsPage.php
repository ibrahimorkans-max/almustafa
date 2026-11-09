<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Accounts\AccountReports;
use App\Domain\Accounts\PartyStatementReport;
use App\Domain\Saas\DataVisibility;
use App\Filament\Support\ExportGuard;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Voucher;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Support\Export\XlsxWriter;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Accounts → Party transactions: every entry on one party's account, the way a bank shows an account — date, number,
 * what it was, money out (debit) and in (credit), and the balance after each — with the opening and closing balance,
 * a search, money in / out, and Excel. From the posted books only.
 */
class PartyTransactionsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Party transactions';

    protected static ?string $title = 'Party transaction history';

    protected static ?string $slug = 'accounts/party-transactions';

    protected static ?int $navigationSort = 25;

    protected static string $view = 'filament.pages.party-transactions';

    public ?string $party = null;

    public string $period = '30d';

    public ?string $from = null;

    public ?string $to = null;

    public string $direction = '';

    public string $search = '';

    /** What each kind of voucher is, in words. */
    public const KINDS = ['purchase' => 'Purchase bill', 'payment' => 'Payment made', 'receipt' => 'Payment received', 'journal' => 'Journal',
        'contra' => 'Contra', 'debit_note' => 'Debit note', 'credit_note' => 'Credit note', 'opening' => 'Opening balance', 'sales' => 'Sales invoice'];

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->hasPermission('ledger.view');
    }

    public function mount(): void
    {
        $asked = request()->query('party');
        $this->party = is_string($asked) && Party::query()->whereKey($asked)->exists() ? $asked : null;
    }

    /** @return array<string, string> */
    public function parties(): array
    {
        return Party::query()->orderBy('name')->get(['id', 'name', 'code'])->mapWithKeys(fn (Party $p) => [$p->id => $p->name.' ('.$p->code.')'])->all();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the period, in Pakistan dates */
    public function range(): array
    {
        $now = CarbonImmutable::now('Asia/Karachi');
        $date = fn (?string $d, CarbonImmutable $else) => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? CarbonImmutable::parse($d, 'Asia/Karachi') : $else;

        return match ($this->period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'month' => [$now->startOfMonth(), $now->endOfDay()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->month >= 7 ? $now->setDate($now->year, 7, 1)->startOfDay() : $now->setDate($now->year - 1, 7, 1)->startOfDay(), $now->endOfDay()],
            'all' => [CarbonImmutable::parse('2000-01-01', 'Asia/Karachi'), $now->endOfDay()],
            'custom' => [$date($this->from, $now->subDays(29))->startOfDay(), $date($this->to, $now)->endOfDay()],
            default => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
        };
    }

    /**
     * The party's account for the period: opening, each entry with its balance after, closing, and the totals.
     *
     * @return array{party: ?Party, opening: int, closing: int, standing: string, lines: list<array<string, mixed>>, total_dr: int, total_cr: int, from: CarbonImmutable, to: CarbonImmutable}|null
     */
    public function account(): ?array
    {
        $party = $this->party !== null ? Party::query()->find($this->party) : null;
        if ($party === null) {
            return null;
        }
        [$from, $to] = $this->range();
        $from = CarbonImmutable::instance(DataVisibility::clampDate($from, 'accounts'));
        $ledger = LedgerAccount::query()->where('party_id', $party->id)->first();
        $book = $ledger === null ? ['opening' => 0, 'closing' => 0, 'lines' => []] : app(AccountReports::class)->ledgerStatement($ledger, $from, $to);
        $term = mb_strtolower(trim($this->search));
        $vouchers = Voucher::query()->whereIn('number', array_column($book['lines'], 'number'))->pluck('id', 'number');
        $lines = array_values(array_filter(array_map(fn (array $l) => $l + [
            'kind' => self::KINDS[$l['type']] ?? ucfirst((string) $l['type']),
            'voucher_id' => $vouchers[$l['number']] ?? null,
        ], $book['lines']), fn (array $l) => ($this->direction === '' || ($this->direction === 'out' ? $l['dr'] > 0 : $l['cr'] > 0))
            && ($term === '' || str_contains(mb_strtolower($l['number'].' '.$l['kind'].' '.$l['narration'].' '.$l['memo']), $term))));

        return ['party' => $party, 'opening' => (int) $book['opening'], 'closing' => (int) $book['closing'],
            'standing' => PartyStatementReport::standing((int) $book['closing']), 'lines' => array_reverse($lines),
            'total_dr' => array_sum(array_column($lines, 'dr')), 'total_cr' => array_sum(array_column($lines, 'cr')), 'from' => $from, 'to' => $to];
    }

    /** "Rs 1,20,000 Dr" — Dr: the party owes the mill; Cr: the mill owes the party. */
    public static function balance(int $paise): string
    {
        return $paise === 0 ? 'Rs 0' : Money::paise(abs($paise))->format().($paise > 0 ? ' Dr' : ' Cr');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')->label('Excel')->icon('heroicon-o-table-cells')->color('gray')
                ->visible(fn () => $this->party !== null && ExportGuard::allowed())
                ->action(function (): ?StreamedResponse {
                    $a = $this->account();
                    if ($a === null) {
                        return null;
                    }
                    $rows = array_map(fn (array $l) => [$l['date'], $l['number'], $l['kind'], trim($l['narration'].' '.$l['memo']),
                        $l['dr'] / 100, $l['cr'] / 100, self::balance((int) $l['balance'])], array_reverse($a['lines']));
                    $bytes = XlsxWriter::build('Transactions', ['Date', 'Number', 'Type', 'Description', 'Debit (Rs)', 'Credit (Rs)', 'Balance'],
                        [['', '', 'Opening balance', '', '', '', self::balance($a['opening'])], ...$rows, ['', '', 'Closing balance', '', $a['total_dr'] / 100, $a['total_cr'] / 100, self::balance($a['closing'])]]);

                    return response()->streamDownload(fn () => print $bytes, 'transactions-'.$a['party']->code.'-'.$a['from']->toDateString().'-'.$a['to']->toDateString().'.xlsx',
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
                }),
            Action::make('statement')->label('Statement (PDF / share)')->icon('heroicon-o-document-text')->color('gray')
                ->visible(fn () => $this->party !== null && PartyStatementPage::canAccess())
                ->url(fn () => PartyStatementPage::getUrl().'?party='.$this->party),
        ];
    }

    /** @return array<string, string> the period choices */
    public function periods(): array
    {
        return ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month',
            'last_month' => 'Last month', 'year' => 'This financial year', 'all' => 'All time', 'custom' => 'Custom dates'];
    }
}
