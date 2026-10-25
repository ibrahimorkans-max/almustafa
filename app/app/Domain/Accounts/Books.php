<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Messaging\PartyEventMessages;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Voucher;
use App\Models\Accounts\VoucherLine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the books. A voucher is posted whole or not at all: debits equal credits
 * to the paisa, every line on exactly one side, the date in an open period. Posted vouchers are
 * never changed; a correction is a reversal voucher.
 */
final class Books
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly PeriodLocks $periods,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{account: LedgerAccount, dr?: int, cr?: int, bill_id?: string|null, memo?: string|null}>  $lines
     * @param  array{source_type?: string|null, source_id?: string|null, reverses_id?: string|null}  $source
     */
    public function post(string $type, CarbonInterface $date, array $lines, ?string $narration = null, array $source = []): Voucher
    {
        if (! isset(Voucher::TYPES[$type])) {
            throw new BusinessRuleViolation("Unknown voucher type {$type}.");
        }
        $lines = array_values(array_filter($lines, fn (array $l) => ($l['dr'] ?? 0) !== 0 || ($l['cr'] ?? 0) !== 0));
        if (count($lines) < 2) {
            throw new BusinessRuleViolation('A voucher needs at least two lines.');
        }
        $dr = 0;
        $cr = 0;
        foreach ($lines as $line) {
            $d = $line['dr'] ?? 0;
            $c = $line['cr'] ?? 0;
            if ($d < 0 || $c < 0 || ($d > 0) === ($c > 0)) {
                throw new BusinessRuleViolation('Each line is either a debit or a credit, with a positive amount.');
            }
            if ($line['account']->status !== 'active') {
                throw new BusinessRuleViolation("Ledger {$line['account']->name} is not active.");
            }
            $dr += $d;
            $cr += $c;
        }
        if ($dr !== $cr) {
            throw new BusinessRuleViolation(sprintf('Debits (%d paise) and credits (%d paise) do not match.', $dr, $cr));
        }
        $this->periods->assertOpen($date);
        $day = CarbonImmutable::instance($date)->setTimezone('Asia/Karachi')->startOfDay();

        return DB::transaction(function () use ($type, $day, $lines, $narration, $source, $dr): Voucher {
            $now = now();
            $voucher = Voucher::query()->create([
                'type' => $type,
                'number' => $this->numbers->next(Voucher::TYPES[$type], $day),
                'voucher_date' => $day->toDateString(),
                'narration' => $narration,
                'total_paise' => $dr,
                'source_type' => $source['source_type'] ?? null,
                'source_id' => $source['source_id'] ?? null,
                'reverses_id' => $source['reverses_id'] ?? null,
                'created_at' => $now,
            ]);
            foreach ($lines as $i => $line) {
                VoucherLine::query()->create([
                    'voucher_id' => $voucher->id,
                    'line_no' => $i + 1,
                    'ledger_account_id' => $line['account']->id,
                    'dr_paise' => $line['dr'] ?? 0,
                    'cr_paise' => $line['cr'] ?? 0,
                    'voucher_date' => $day->toDateString(),
                    'bill_id' => $line['bill_id'] ?? null,
                    'memo' => isset($line['memo']) ? mb_substr($line['memo'], 0, 255) : null,
                    'created_at' => $now,
                ]);
            }
            $this->audit->record('voucher.posted', $voucher, null, [
                'type' => $type, 'number' => $voucher->number, 'date' => $day->toDateString(), 'total_paise' => $dr,
                'lines' => array_map(fn (array $l) => [$l['account']->code, $l['dr'] ?? 0, $l['cr'] ?? 0], $lines),
            ], array_filter($source));
            // Each party in it hears of it (once the voucher is saved), with its balance after it.
            app(PartyEventMessages::class)->voucherPosted($voucher, $lines, fn (LedgerAccount $ledger) => $this->balance($ledger));

            return $voucher;
        });
    }

    /** Opposite entries of a voucher, dated $date (default today). One reversal per voucher. */
    public function reverse(Voucher $voucher, string $reason, ?CarbonInterface $date = null): Voucher
    {
        if ($voucher->isReversal()) {
            throw new BusinessRuleViolation('A reversal cannot itself be reversed; post a new voucher instead.');
        }
        if (Voucher::query()->where('reverses_id', $voucher->id)->exists()) {
            throw new BusinessRuleViolation("Voucher {$voucher->number} was already reversed.");
        }

        $lines = VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->with('account')->get()
            ->map(fn (VoucherLine $l) => [
                'account' => $l->account, 'dr' => $l->cr_paise, 'cr' => $l->dr_paise, 'bill_id' => $l->bill_id, 'memo' => $l->memo,
            ])->all();

        return $this->post($voucher->type, $date ?? now(), array_values($lines), "Reversal of {$voucher->number}: {$reason}", [
            'source_type' => $voucher->source_type, 'source_id' => $voucher->source_id, 'reverses_id' => $voucher->id,
        ]);
    }

    /** Signed balance in paise (debit positive) up to and including $asOf. */
    public function balance(LedgerAccount $account, ?CarbonInterface $asOf = null): int
    {
        $row = VoucherLine::query()->toBase()
            ->where('ledger_account_id', $account->id)
            ->when($asOf, fn ($q) => $q->where('voucher_date', '<=', $asOf->toDateString()))
            ->selectRaw('COALESCE(SUM(dr_paise), 0) AS dr, COALESCE(SUM(cr_paise), 0) AS cr')
            ->first();

        return (int) ($row->dr ?? 0) - (int) ($row->cr ?? 0);
    }

    /**
     * Vouchers whose lines do not balance (should never happen; checked nightly).
     *
     * @return list<array{number: string, dr: int, cr: int, total: int}>
     */
    public function verify(): array
    {
        $bad = [];
        $sums = VoucherLine::query()->toBase()->groupBy('voucher_id')
            ->selectRaw('voucher_id, SUM(dr_paise) AS dr, SUM(cr_paise) AS cr')->get()->keyBy('voucher_id');
        foreach (Voucher::query()->get(['id', 'number', 'total_paise']) as $voucher) {
            $s = $sums[$voucher->id] ?? null;
            $dr = (int) ($s->dr ?? 0);
            $cr = (int) ($s->cr ?? 0);
            if ($dr !== $cr || $dr !== $voucher->total_paise) {
                $bad[] = ['number' => $voucher->number, 'dr' => $dr, 'cr' => $cr, 'total' => $voucher->total_paise];
            }
        }

        return $bad;
    }
}
