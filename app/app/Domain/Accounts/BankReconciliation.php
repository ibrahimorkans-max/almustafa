<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\BankStatementLine;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Payment;
use App\Models\Accounts\VoucherLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bank statement import and matching against the bank ledger. Matching never changes the books;
 * it only links a statement line to the voucher line it confirms.
 *
 * CSV columns (header row required, names case-insensitive): date, description, reference, debit, credit.
 * Dates as DD/MM/YYYY or YYYY-MM-DD; amounts in rupees.
 */
final class BankReconciliation
{
    public const MATCH_WINDOW_DAYS = 5;

    public function __construct(private readonly ActorContext $actor) {}

    /** @return array{imported: int, duplicates: int, matched: int} */
    public function import(LedgerAccount $bank, string $csv): array
    {
        $this->require();
        if ($bank->kind !== 'bank') {
            throw new BusinessRuleViolation('Choose a mill bank account.');
        }
        $rows = array_map(fn (string $line) => str_getcsv($line, escape: ''), preg_split('/\r\n|\n|\r/', trim($csv)) ?: []);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);
        foreach (['date', 'description', 'debit', 'credit'] as $required) {
            if (! in_array($required, $header, true)) {
                throw new BusinessRuleViolation("The statement needs a '{$required}' column.");
            }
        }

        $imported = 0;
        $duplicates = 0;
        $seen = [];
        DB::transaction(function () use ($rows, $header, $bank, &$imported, &$duplicates, &$seen): void {
            foreach ($rows as $n => $cells) {
                if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue;
                }
                $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
                $date = self::date((string) $row['date']) ?? throw new BusinessRuleViolation('Row '.($n + 2).': unreadable date.');
                $debit = self::paise((string) $row['debit']);
                $credit = self::paise((string) $row['credit']);
                if (($debit > 0) === ($credit > 0)) {
                    throw new BusinessRuleViolation('Row '.($n + 2).': exactly one of debit / credit must have an amount.');
                }
                $reference = trim((string) ($row['reference'] ?? '')) ?: null;
                $description = mb_substr(trim((string) $row['description']), 0, 255);
                $base = implode('|', [$date, $description, $reference, $debit, $credit]);
                $seen[$base] = ($seen[$base] ?? 0) + 1; // identical rows in one statement stay distinct
                $fingerprint = hash('sha256', $base.'|'.$seen[$base]);

                if (BankStatementLine::query()->where('ledger_account_id', $bank->id)->where('fingerprint', $fingerprint)->exists()) {
                    $duplicates++;

                    continue;
                }
                BankStatementLine::query()->create([
                    'ledger_account_id' => $bank->id, 'txn_date' => $date, 'description' => $description,
                    'reference' => $reference, 'debit_paise' => $debit, 'credit_paise' => $credit, 'fingerprint' => $fingerprint,
                ]);
                $imported++;
            }
        });

        return ['imported' => $imported, 'duplicates' => $duplicates, 'matched' => $this->autoMatch($bank)];
    }

    /**
     * Same amount on the same side within a few days; a payment UTR / cheque number found in the
     * statement wins over date proximity. Ambiguous lines are left for a person.
     */
    public function autoMatch(LedgerAccount $bank): int
    {
        $matched = 0;
        foreach (BankStatementLine::query()->where('ledger_account_id', $bank->id)->whereNull('voucher_line_id')->orderBy('txn_date')->get() as $line) {
            $candidates = $this->candidates($line);
            $pick = null;
            foreach ($candidates as $c) {
                $ref = Payment::query()->where('voucher_id', $c->voucher_id)->value('reference');
                if ($ref !== null && (str_contains(strtoupper($line->description), $ref) || strtoupper((string) $line->reference) === $ref)) {
                    $pick = $c;
                    break;
                }
            }
            $pick ??= $candidates->count() === 1 ? $candidates->first() : null;
            if ($pick !== null) {
                $this->link($line, $pick, 'auto');
                $matched++;
            }
        }

        return $matched;
    }

    public function match(BankStatementLine $line, VoucherLine $voucherLine): BankStatementLine
    {
        $this->require();
        if ($line->voucher_line_id !== null) {
            throw new BusinessRuleViolation('This statement line is already matched.');
        }
        if ($voucherLine->ledger_account_id !== $line->ledger_account_id
            || $voucherLine->dr_paise !== $line->credit_paise || $voucherLine->cr_paise !== $line->debit_paise) {
            throw new BusinessRuleViolation('The book entry must be on the same bank ledger, same side and same amount.');
        }
        if (BankStatementLine::query()->where('voucher_line_id', $voucherLine->id)->exists()) {
            throw new BusinessRuleViolation('That book entry is already matched to another statement line.');
        }

        return $this->link($line, $voucherLine, 'manual');
    }

    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        $this->require();
        $line->forceFill(['voucher_line_id' => null, 'match_method' => null, 'matched_by' => null, 'matched_at' => null])->save();

        return $line;
    }

    /** @return Collection<int, VoucherLine> */
    public function candidates(BankStatementLine $line): Collection
    {
        $from = $line->txn_date->copy()->subDays(self::MATCH_WINDOW_DAYS)->toDateString();
        $to = $line->txn_date->copy()->addDays(self::MATCH_WINDOW_DAYS)->toDateString();

        return VoucherLine::query()
            ->where('ledger_account_id', $line->ledger_account_id)
            ->where('dr_paise', $line->credit_paise)->where('cr_paise', $line->debit_paise)
            ->whereBetween('voucher_date', [$from, $to])
            ->whereNotIn('id', BankStatementLine::query()->whereNotNull('voucher_line_id')->select('voucher_line_id'))
            ->with('voucher')->orderBy('voucher_date')->get();
    }

    private function link(BankStatementLine $line, VoucherLine $voucherLine, string $method): BankStatementLine
    {
        $line->forceFill([
            'voucher_line_id' => $voucherLine->id, 'match_method' => $method,
            'matched_by' => $this->actor->accountId(), 'matched_at' => now(),
        ])->save();

        return $line;
    }

    private static function date(string $value): ?string
    {
        $value = trim($value);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }
            // Round trip rejects overflowing dates such as 31/02 (which PHP would turn into 03/03).
            if ($parsed !== null && $parsed->format($format) === $value) {
                return $parsed->toDateString();
            }
        }

        return null;
    }

    private static function paise(string $rupees): int
    {
        $clean = str_replace([',', ' ', '₹'], '', trim($rupees));
        if ($clean === '' || $clean === '-') {
            return 0;
        }
        if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $clean)) {
            throw new BusinessRuleViolation("Unreadable amount '{$rupees}'.");
        }

        return (int) bcmul($clean, '100', 0);
    }

    private function require(): void
    {
        $account = $this->actor->account();
        if ($account === null || ! $account->hasPermission('bank.view') || ! $account->hasPermission('ledger.post')) {
            throw new BusinessRuleViolation("You need 'bank.view' and 'ledger.post' for this.");
        }
    }
}
