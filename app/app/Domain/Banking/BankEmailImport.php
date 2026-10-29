<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounts\BankReconciliation;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\BankStatementLine;
use App\Models\Banking\BankEmailTemplate;
use App\Models\Banking\BankEmailTransaction;
use App\Models\Banking\EmailConnection;
use App\Models\Banking\EmailSyncRun;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bank transactions from notification e-mails:
 *
 *  sync      reads a connected mailbox (by the schedule or "Sync now"), keeps each e-mail once
 *            (unique per mailbox), reads it with its bank's template, marks a transaction already seen
 *            (same account, date, side, amount and reference) as a duplicate, and matches the party
 *            on verified facts only (PartyMatcher). Every reading is logged (EmailSyncRun).
 *  confirm   a person checks it (and may choose the party): it becomes "confirmed".
 *  ignore    not a transaction to keep (a promotion, a duplicate notice): "ignored", with a reason.
 *  toStatement  a confirmed transaction becomes a line of the bank statement, where it is reconciled
 *            with the books like any statement line (BankReconciliation::autoMatch).
 *
 * An e-mail alone never posts anything to the books.
 */
final class BankEmailImport
{
    public const SERIES = 'BNK';

    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly BankEmailParser $parser,
        private readonly PartyMatcher $matcher,
        private readonly DocumentNumbers $numbers,
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /** Whether the product owner left bank e-mail import on for this mill's customer. */
    public static function enabledFor(?string $entityId): bool
    {
        if ($entityId === null) {
            return false;
        }

        return app(TenantContext::class)->runAsSystem(function () use ($entityId): bool {
            $customerId = Entity::query()->whereKey($entityId)->value('customer_id');

            return $customerId === null || (bool) (Customer::query()->whereKey($customerId)->value('bank_email_enabled') ?? true);
        });
    }

    /** Reads the mailbox now. Returns the run (also recorded when it fails). */
    public function sync(EmailConnection $connection, string $trigger = 'manual'): EmailSyncRun
    {
        $run = EmailSyncRun::query()->create([
            'email_connection_id' => $connection->id, 'trigger' => $trigger, 'started_at' => now(),
        ]);
        $counts = ['fetched' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0];
        try {
            if (! self::enabledFor($connection->entity_id)) {
                throw new BusinessRuleViolation('Bank e-mail import is switched off for this company.');
            }
            if (! in_array($connection->status, ['connected', 'error'], true)) {
                throw new BusinessRuleViolation('The mailbox is not connected.');
            }
            // A day of overlap: an e-mail already kept is skipped by its id.
            $since = CarbonImmutable::instance($connection->last_success_at ?? now()->subDays(7))->subDay();
            $messages = $this->mailbox->fetch($connection, $since);
            $templates = BankEmailTemplate::query()->where('active', true)->get();
            $counts['fetched'] = count($messages);
            foreach ($messages as $message) {
                $result = $this->keep($connection, $message, $templates);
                if ($result !== null) {
                    $counts[$result]++;
                }
            }
            $connection->forceFill(['last_synced_at' => now(), 'last_success_at' => now(), 'last_error' => null, 'status' => 'connected'])->save();
        } catch (Throwable $e) {
            $message = $e instanceof BusinessRuleViolation ? $e->getMessage() : 'Reading the mailbox failed: '.class_basename($e).'.';
            if (! $e instanceof BusinessRuleViolation) {
                report($e);
            }
            // A connected mailbox that fails is shown as "Error" until a reading succeeds again.
            $connection->forceFill(['last_synced_at' => now(), 'last_error' => mb_substr($message, 0, 500),
                'status' => in_array($connection->status, ['connected', 'error'], true) ? 'error' : $connection->status])->save();
            $run->error = mb_substr($message, 0, 500);
        }
        $run->forceFill($counts + ['finished_at' => now()])->save();

        return $run;
    }

    /**
     * Keeps one e-mail. Returns which counter it adds to, or null when it was already kept.
     *
     * @param  iterable<BankEmailTemplate>  $templates
     */
    private function keep(EmailConnection $connection, MailMessage $message, iterable $templates): ?string
    {
        $id = mb_substr($message->id, 0, 255);
        if (BankEmailTransaction::query()->where('email_connection_id', $connection->id)->where('message_id', $id)->exists()) {
            return null;
        }
        $parsed = $this->parser->parse($message, $templates);
        // E-mails from no known bank are not kept at all: the mailbox may hold anything else.
        if ($parsed['template'] === null) {
            return null;
        }

        return DB::transaction(function () use ($connection, $message, $id, $parsed): string {
            $f = $parsed['fields'];
            $tx = new BankEmailTransaction([
                'email_connection_id' => $connection->id,
                'bank_email_template_id' => $parsed['template']?->id,
                'ledger_account_id' => $connection->ledger_account_id,
                'message_id' => $id,
                'message_from' => mb_substr($message->from, 0, 160),
                'message_subject' => mb_substr($message->subject, 0, 255),
                'received_at' => $message->receivedAt,
                'excerpt' => mb_substr($message->text, 0, 4000),
            ] + $f);
            $tx->number = $this->numbers->next(self::SERIES);

            if ($parsed['error'] !== null) {
                $tx->import_status = 'failed';
                $tx->parse_error = $parsed['error'];
                $tx->save();

                return 'failed';
            }

            $tx->fingerprint = hash('sha256', implode('|', [$connection->ledger_account_id, $f['txn_date'], $f['direction'], $f['amount_paise'], strtoupper((string) $f['reference'])]));
            $twin = $f['reference'] !== null
                ? BankEmailTransaction::query()->where('fingerprint', $tx->fingerprint)->where('import_status', 'parsed')->value('number')
                : null;
            if ($twin !== null) {
                $tx->import_status = 'duplicate';
                $tx->parse_error = 'Same transaction as '.$twin.'.';
                $tx->reconciliation_status = 'ignored';
                $tx->save();

                return 'duplicates';
            }

            $match = $this->matcher->match($f['counterparty_account'], $f['reference'], $f['counterparty']);
            $tx->party_id = $match['party_id'];
            $tx->match_status = $match['status'];
            $tx->match_reason = $match['reason'];
            $tx->save();

            return 'imported';
        });
    }

    /** A person checked it; a suggested or missing party may be set (or left empty) here. */
    public function confirm(BankEmailTransaction $tx, ?Party $party, bool $changeParty = false): BankEmailTransaction
    {
        $this->require('bank.view');

        return DB::transaction(function () use ($tx, $party, $changeParty): BankEmailTransaction {
            $tx = BankEmailTransaction::query()->lockForUpdate()->findOrFail($tx->id);
            if ($tx->import_status !== 'parsed' || ! in_array($tx->reconciliation_status, ['pending', 'confirmed'], true)) {
                throw new BusinessRuleViolation('Only a transaction to review can be confirmed.');
            }
            if ($changeParty) {
                $tx->party_id = $party?->id;
                $tx->match_status = $party === null ? 'unmatched' : 'matched';
                $tx->match_reason = $party === null ? 'No party (set by a person).' : 'Party confirmed by a person.';
            } elseif ($tx->match_status === 'suggested') {
                $tx->match_status = $tx->party_id === null ? 'unmatched' : 'matched';
                $tx->match_reason = 'Suggested party confirmed by a person.';
            }
            $tx->reconciliation_status = 'confirmed';
            $tx->reviewed_by = $this->actor->account()?->id;
            $tx->reviewed_at = now();
            $tx->save();

            return $tx;
        });
    }

    public function ignore(BankEmailTransaction $tx, string $reason): BankEmailTransaction
    {
        $this->require('bank.view');
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessRuleViolation('Give a reason.');
        }
        if ($tx->reconciliation_status === 'on_statement') {
            throw new BusinessRuleViolation('It is already on the bank statement.');
        }
        $tx->forceFill(['reconciliation_status' => 'ignored', 'reviewed_by' => $this->actor->account()?->id, 'reviewed_at' => now()])->save();
        $this->audit->record('bank_email.ignored', $tx, null, null, ['reason' => trim($reason)]);

        return $tx;
    }

    /** A confirmed transaction onto the bank statement (once), then the statement is matched to the books. */
    public function toStatement(BankEmailTransaction $tx): BankEmailTransaction
    {
        $this->require('ledger.post');

        return DB::transaction(function () use ($tx): BankEmailTransaction {
            $tx = BankEmailTransaction::query()->lockForUpdate()->findOrFail($tx->id);
            if ($tx->reconciliation_status !== 'confirmed') {
                throw new BusinessRuleViolation('Confirm the transaction first.');
            }
            $bank = $tx->bankLedger;
            if ($bank === null || $bank->kind !== 'bank') {
                throw new BusinessRuleViolation('Set the mill bank account of this mailbox first.');
            }
            $line = BankStatementLine::query()->create([
                'ledger_account_id' => $bank->id,
                'txn_date' => $tx->txn_date?->toDateString() ?? now('Asia/Karachi')->toDateString(),
                'description' => mb_substr(trim(($tx->counterparty ?? '').' '.$tx->number.' (e-mail)'), 0, 255),
                'reference' => $tx->reference,
                'debit_paise' => $tx->direction === 'debit' ? (int) $tx->amount_paise : 0,
                'credit_paise' => $tx->direction === 'credit' ? (int) $tx->amount_paise : 0,
                // The e-mail's own fingerprint: sending it again is refused by the statement's unique key.
                'fingerprint' => hash('sha256', 'email|'.$tx->email_connection_id.'|'.$tx->message_id),
            ]);
            $tx->forceFill(['reconciliation_status' => 'on_statement', 'bank_statement_line_id' => $line->id])->save();
            app(BankReconciliation::class)->autoMatch($bank);

            return $tx;
        });
    }

    /** Reads every connected mailbox that is due, mill by mill (the scheduled job). */
    public function syncDue(): int
    {
        $done = 0;
        $connections = $this->tenant->runAsSystem(fn () => EmailConnection::query()->whereIn('status', ['connected', 'error'])->get());
        foreach ($connections as $connection) {
            if (! $connection->isDue()) {
                continue;
            }
            $this->tenant->runInEntity($connection->entity_id, fn () => $this->sync($connection, 'schedule'));
            $done++;
        }

        return $done;
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}
