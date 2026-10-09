<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\BankFile;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-transfer CSV for the bank portal, from released bank-mode payments of one mill bank
 * account. Each payment goes into exactly one file. Downloading shows full account numbers, so it
 * needs `bank.view` and is audited; the stored SHA-256 shows the file was not edited in between.
 */
final class BankFiles
{
    public const HEADER = ['Payment ref', 'Mode', 'Amount', 'Beneficiary name', 'Beneficiary IBAN', 'Bank code', 'Debit IBAN', 'Narration'];

    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly AuditLogger $audit,
        private readonly ActorContext $actor,
    ) {}

    public function create(LedgerAccount $bank): BankFile
    {
        $this->require('payment.approve');
        if ($bank->kind !== 'bank') {
            throw new BusinessRuleViolation('Choose a mill bank account.');
        }

        return DB::transaction(function () use ($bank): BankFile {
            $payments = Payment::query()->where('from_ledger_id', $bank->id)->where('status', Payment::APPROVED)
                ->whereIn('mode', Payment::BANK_MODES)->whereNull('bank_file_id')->lockForUpdate()->orderBy('number')->get();
            if ($payments->isEmpty()) {
                throw new BusinessRuleViolation('No released bank payments are waiting for a file.');
            }
            $file = BankFile::query()->create([
                'number' => $this->numbers->next(DocumentNumbers::BANK_FILE),
                'ledger_account_id' => $bank->id,
                'payment_count' => $payments->count(),
                'total_paise' => (int) $payments->sum('amount_paise'),
                'sha256' => str_repeat('0', 64),
            ]);
            foreach ($payments as $payment) {
                $payment->forceFill(['bank_file_id' => $file->id])->save();
            }
            $file->forceFill(['sha256' => hash('sha256', $this->csv($file, audit: false))])->save();

            return $file;
        });
    }

    public function csv(BankFile $file, bool $audit = true): string
    {
        if ($audit) {
            $this->require('bank.view');
        }
        $bank = LedgerAccount::query()->findOrFail($file->ledger_account_id);
        $out = fopen('php://temp', 'r+') ?: throw new BusinessRuleViolation('Cannot build the file.');
        fputcsv($out, self::HEADER, escape: '');
        foreach (Payment::query()->where('bank_file_id', $file->id)->with('party')->orderBy('number')->get() as $p) {
            fputcsv($out, [
                $p->number,
                strtoupper($p->mode),
                number_format($p->amount_paise / 100, 2, '.', ''),
                self::safe($p->payee['holder'] ?? $p->party->name),
                $p->payee_account_number,
                $p->payee['bank_code'] ?? '',
                $bank->bank_account_number,
                self::safe(mb_substr("{$p->number} {$p->party->name}", 0, 30)),
            ], escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        if ($audit) {
            if (! hash_equals($file->sha256, hash('sha256', $csv))) {
                throw new BusinessRuleViolation("Bank file {$file->number} no longer matches what was approved; do not upload it.");
            }
            $this->audit->record('bank_file.downloaded', $file, meta: ['payments' => $file->payment_count, 'total_paise' => $file->total_paise]);
        }

        return $csv;
    }

    /** Neutralise spreadsheet formula injection in free-text cells. */
    private static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}
