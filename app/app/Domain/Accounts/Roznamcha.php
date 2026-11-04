<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Approvals\SelfApproval;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Files\FileStore;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\RoznamchaLine;
use App\Models\Accounts\RoznamchaPage;
use App\Models\Masters\Party;
use App\Support\Values\UrduDigits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The digital roznamcha (daily cash book):
 *
 *  record  a page from the camera, a scanner, a PDF or image, or typed: the original is kept (its
 *          SHA-256 proves it unchanged), OCR suggests the lines, and the page waits as "To check";
 *  lines   the lines are corrected while the page is to check (each: a party or account, cash in or out);
 *  verify  a second person checks the page against the scan (the person who entered it may only when the
 *          mill lets its main users approve their own work);
 *  post    each line becomes a cash receipt or payment voucher, once; a posted page cannot change.
 */
final class Roznamcha
{
    public const SERIES = 'RZN';

    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly FileStore $files,
        private readonly RoznamchaOcr $ocr,
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly ActorContext $actor,
    ) {}

    /** A new page; with a scan, OCR fills the suggested lines. */
    public function record(CarbonImmutable $date, string $source, ?string $bytes = null, ?string $note = null, ?LedgerAccount $cash = null): RoznamchaPage
    {
        $this->require();
        if (! isset(RoznamchaPage::SOURCES[$source])) {
            throw new BusinessRuleViolation('Unknown source.');
        }
        if ($source !== 'manual' && ($bytes === null || $bytes === '')) {
            throw new BusinessRuleViolation('Attach the scanned page, or choose "Typed by hand".');
        }
        if ($date->isAfter(now('Asia/Karachi')->endOfDay())) {
            throw new BusinessRuleViolation('A roznamcha page cannot be dated in the future.');
        }
        $cash ??= $this->chart->system('cash');
        if ($cash->kind !== 'cash') {
            throw new BusinessRuleViolation('Choose a cash account.');
        }

        return DB::transaction(function () use ($date, $source, $bytes, $note, $cash): RoznamchaPage {
            $file = $bytes !== null && $bytes !== '' ? $this->files->put($bytes) : null;
            $read = $file !== null ? $this->ocr->read($bytes, $file->mime) : ['engine' => 'none', 'text' => '', 'lines' => [], 'error' => null];

            $page = new RoznamchaPage([
                'entry_date' => $date->toDateString(), 'source' => $source, 'stored_file_id' => $file?->id,
                'ocr_text' => $read['text'] !== '' ? $read['text'] : $read['error'], 'ocr_engine' => $file !== null ? $read['engine'] : null,
                'cash_ledger_id' => $cash->id, 'note' => $note,
            ]);
            $page->number = $this->numbers->next(self::SERIES, $date);
            $page->save();

            $position = 0;
            foreach ($read['lines'] as $l) {
                $debit = self::paise($l['debit']);
                $credit = self::paise($l['credit']);
                if (($debit > 0) === ($credit > 0)) {
                    continue;
                }
                [$partyId, $accountId] = [null, null];
                foreach (array_unique(array_filter([$l['match'] ?? '', $l['account_en'] ?? '', $l['account']])) as $name) {
                    [$partyId, $accountId] = $this->match((string) $name);
                    if ($partyId !== null || $accountId !== null) {
                        break;
                    }
                }
                RoznamchaLine::query()->create([
                    'roznamcha_page_id' => $page->id, 'position' => ++$position,
                    'party_id' => $partyId, 'ledger_account_id' => $accountId,
                    'account_text' => ($l['account'] ?: ($l['account_en'] ?? '')) ?: null, 'debit_paise' => $debit, 'credit_paise' => $credit,
                    'narration' => $l['narration'] ?: null, 'reference' => $l['reference'] ?: null,
                ]);
            }

            return $page;
        });
    }

    /**
     * Replaces the lines of a page that is still to check.
     *
     * @param  list<array{party_id?: string|null, ledger_account_id?: string|null, account_text?: string|null, debit?: string|null, credit?: string|null, narration?: string|null, reference?: string|null}>  $rows
     */
    public function saveLines(RoznamchaPage $page, array $rows): RoznamchaPage
    {
        $this->require();

        return DB::transaction(function () use ($page, $rows): RoznamchaPage {
            $page = RoznamchaPage::query()->lockForUpdate()->findOrFail($page->id);
            if ($page->status !== 'draft') {
                throw new BusinessRuleViolation('Only a page that is still to check can be changed.');
            }
            RoznamchaLine::query()->where('roznamcha_page_id', $page->id)->delete();
            foreach (array_values($rows) as $i => $row) {
                $debit = self::paise((string) ($row['debit'] ?? ''));
                $credit = self::paise((string) ($row['credit'] ?? ''));
                if (($debit > 0) === ($credit > 0)) {
                    throw new BusinessRuleViolation('Line '.($i + 1).': enter either cash received or cash paid.');
                }
                $account = filled($row['ledger_account_id'] ?? null) ? LedgerAccount::query()->find($row['ledger_account_id']) : null;
                if ($account !== null && in_array($account->kind, ['cash', 'bank'], true)) {
                    throw new BusinessRuleViolation('Line '.($i + 1).': a cash or bank account cannot be the other side of a cash entry; use a contra.');
                }
                RoznamchaLine::query()->create([
                    'roznamcha_page_id' => $page->id, 'position' => $i + 1,
                    'party_id' => filled($row['party_id'] ?? null) ? $row['party_id'] : null,
                    'ledger_account_id' => $account?->id,
                    'account_text' => filled($row['account_text'] ?? null) ? mb_substr((string) $row['account_text'], 0, 160) : null,
                    'debit_paise' => $debit, 'credit_paise' => $credit,
                    'narration' => filled($row['narration'] ?? null) ? mb_substr((string) $row['narration'], 0, 300) : null,
                    'reference' => filled($row['reference'] ?? null) ? mb_substr((string) $row['reference'], 0, 80) : null,
                ]);
            }

            return $page;
        });
    }

    public function verify(RoznamchaPage $page): RoznamchaPage
    {
        $this->require();
        $me = $this->actor->account();

        return DB::transaction(function () use ($page, $me): RoznamchaPage {
            $page = RoznamchaPage::query()->lockForUpdate()->findOrFail($page->id);
            if ($page->status !== 'draft') {
                throw new BusinessRuleViolation('This page is already checked.');
            }
            if ($page->created_by === $me?->id && ! SelfApproval::allowed($me)) {
                throw new BusinessRuleViolation('A second person checks the page against the scan.');
            }
            $lines = $page->lines()->get();
            if ($lines->isEmpty()) {
                throw new BusinessRuleViolation('The page has no lines.');
            }
            foreach ($lines as $line) {
                if ($line->party_id === null && $line->ledger_account_id === null) {
                    throw new BusinessRuleViolation('Line '.$line->position.' ('.($line->account_text ?? 'no name').'): choose the party or account.');
                }
            }
            $page->forceFill(['status' => 'verified', 'verified_by' => $me?->id, 'verified_at' => now()])->save();

            return $page;
        });
    }

    /**
     * Each line as a cash receipt (cash received) or payment (cash paid) voucher, once. When the day's cash paid out
     * is over its budget (RoznamchaBudgets), the page is posted only with the reason, which is kept on it.
     */
    public function post(RoznamchaPage $page, ?string $overBudgetReason = null): RoznamchaPage
    {
        $this->require();

        return DB::transaction(function () use ($page, $overBudgetReason): RoznamchaPage {
            $page = RoznamchaPage::query()->lockForUpdate()->findOrFail($page->id);
            if ($page->status !== 'verified') {
                throw new BusinessRuleViolation($page->status === 'posted' ? 'This page is already posted.' : 'Check the page before posting.');
            }
            $day = app(RoznamchaBudgets::class)->day(CarbonImmutable::parse($page->entry_date->toDateString(), 'Asia/Karachi'));
            $reason = trim((string) $overBudgetReason);
            if ($day['over'] && $page->lines()->sum('debit_paise') > 0) {
                if (mb_strlen($reason) < 5) {
                    throw new BusinessRuleViolation('Cash paid out on '.$page->entry_date->format('d M Y').' is Rs '.number_format($day['paid'] / 100)
                        .', over its budget of Rs '.number_format((int) $day['budget'] / 100).' by Rs '.number_format(($day['paid'] - (int) $day['budget']) / 100).'. Give the reason to post it.');
                }
                $page->forceFill(['over_budget_reason' => mb_substr($reason, 0, 300)]);
            }
            $cash = $page->cashLedger ?? $this->chart->system('cash');
            $date = CarbonImmutable::parse($page->entry_date->toDateString(), 'Asia/Karachi');
            foreach ($page->lines()->with(['party', 'ledgerAccount'])->get() as $line) {
                if ($line->voucher_id !== null) {
                    continue;
                }
                $other = $line->party !== null ? $this->chart->partyLedger($line->party) : $line->ledgerAccount;
                if ($other === null) {
                    throw new BusinessRuleViolation('Line '.$line->position.' has no party or account.');
                }
                $narration = trim(($line->narration ?? $line->account_text ?? $other->name).' — roznamcha '.$page->number);
                $voucher = $line->credit_paise > 0
                    ? $this->books->post('receipt', $date, [['account' => $cash, 'dr' => $line->credit_paise], ['account' => $other, 'cr' => $line->credit_paise]], $narration,
                        ['source_type' => 'roznamcha', 'source_id' => $page->id])
                    : $this->books->post('payment', $date, [['account' => $other, 'dr' => $line->debit_paise], ['account' => $cash, 'cr' => $line->debit_paise]], $narration,
                        ['source_type' => 'roznamcha', 'source_id' => $page->id]);
                $line->forceFill(['voucher_id' => $voucher->id])->save();
            }
            $page->forceFill(['status' => 'posted', 'posted_by' => $this->actor->account()?->id, 'posted_at' => now()])->save();

            return $page;
        });
    }

    /**
     * The party or account a name written on the page means: the party of that exact name or code, else the
     * only party whose name contains it (or is contained in it); else the only expense or income account
     * of that name, or containing it (diesel, bijli, mazdoori…). Anything unsure is left for the person to choose.
     *
     * @return array{0: string|null, 1: string|null} party id, ledger account id
     */
    private function match(string $written): array
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $written)));
        if (mb_strlen($name) < 2) {
            return [null, null];
        }
        $one = fn ($rows) => $rows->count() === 1 ? $rows->first()->id : null;
        $parties = Party::query()->where('status', 'active');
        $party = $one((clone $parties)->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [$name])->orWhereRaw('LOWER(code) = ?', [$name]))->limit(2)->get(['id']))
            ?? (mb_strlen($name) >= 4 ? $one((clone $parties)->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.$name.'%'])
                ->orWhereRaw(self::castText('?').' LIKE '.self::concatLike('LOWER(name)'), [$name]))->limit(2)->get(['id'])) : null);
        if ($party !== null) {
            return [$party, null];
        }
        $accounts = LedgerAccount::query()->where('status', 'active')->whereIn('kind', ['general', 'system'])->whereNull('party_id');
        $account = $one((clone $accounts)->whereRaw('LOWER(name) = ?', [$name])->limit(2)->get(['id']))
            ?? (mb_strlen($name) >= 4 ? $one((clone $accounts)->whereRaw('LOWER(name) LIKE ?', ['%'.$name.'%'])->limit(2)->get(['id'])) : null);

        return [null, $account];
    }

    /** A value as text, so the database knows its type in a LIKE. */
    private static function castText(string $value): string
    {
        return DB::getDriverName() === 'pgsql' ? "CAST({$value} AS TEXT)" : "CAST({$value} AS CHAR)";
    }

    /** "'%' || column || '%'" in this database's words. */
    private static function concatLike(string $column): string
    {
        return DB::getDriverName() === 'pgsql' ? "'%' || {$column} || '%'" : "CONCAT('%', {$column}, '%')";
    }

    /** "1,25,000.50" → 12500050; empty → 0. */
    private static function paise(string $rupees): int
    {
        $clean = str_replace([',', ' '], '', trim(UrduDigits::toLatin($rupees)));
        if ($clean === '') {
            return 0;
        }
        if (! preg_match(Units::DECIMAL_2, $clean)) {
            throw new BusinessRuleViolation("\"{$rupees}\" is not an amount.");
        }

        return Units::rupeesToPaise($clean);
    }

    private function require(): void
    {
        if (! ($this->actor->account()?->hasPermission('ledger.post') ?? false)) {
            throw new BusinessRuleViolation("You need 'ledger.post' for the roznamcha.");
        }
    }
}
