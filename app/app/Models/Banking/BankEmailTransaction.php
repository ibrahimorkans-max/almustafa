<?php

declare(strict_types=1);

namespace App\Models\Banking;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\BankStatementLine;
use App\Models\Accounts\LedgerAccount;
use App\Models\Masters\Party;
use App\Support\Eloquent\HasOptimisticLock;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bank transaction read from a notification e-mail. It is a notice, not proof that the money settled:
 * it changes nothing in the books until a person confirms it and sends it to the bank statement (where
 * it is reconciled like any statement line).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $email_connection_id
 * @property string|null $bank_email_template_id
 * @property string|null $ledger_account_id
 * @property string $message_id
 * @property string|null $message_from
 * @property string|null $message_subject
 * @property Carbon|null $received_at
 * @property string|null $excerpt
 * @property string|null $bank_name
 * @property Carbon|null $txn_date
 * @property string|null $txn_time
 * @property string|null $direction credit | debit
 * @property int|null $amount_paise
 * @property string|null $reference
 * @property string|null $account_hint
 * @property string|null $counterparty
 * @property string|null $counterparty_account
 * @property string|null $party_id
 * @property string $match_status matched | suggested | unmatched
 * @property string|null $match_reason
 * @property string $import_status parsed | failed | duplicate
 * @property string|null $parse_error
 * @property string $reconciliation_status pending | confirmed | ignored | on_statement
 * @property string|null $fingerprint
 * @property string|null $bank_statement_line_id
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 * @property-read Party|null $party
 * @property-read LedgerAccount|null $bankLedger
 */
class BankEmailTransaction extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;

    public const MATCH_LABELS = ['matched' => 'Matched', 'suggested' => 'Check the party', 'unmatched' => 'No party'];

    public const RECONCILIATION_LABELS = ['pending' => 'To review', 'confirmed' => 'Confirmed', 'ignored' => 'Ignored', 'on_statement' => 'On bank statement'];

    public const IMPORT_LABELS = ['parsed' => 'Read', 'failed' => 'Could not read', 'duplicate' => 'Duplicate'];

    protected $fillable = [
        'email_connection_id', 'bank_email_template_id', 'ledger_account_id', 'message_id', 'message_from', 'message_subject', 'received_at', 'excerpt',
        'bank_name', 'txn_date', 'txn_time', 'direction', 'amount_paise', 'reference', 'account_hint', 'counterparty', 'counterparty_account',
        'party_id', 'match_status', 'match_reason', 'import_status', 'parse_error', 'fingerprint',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'txn_date' => 'date', 'amount_paise' => 'integer', 'reviewed_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function bankLedger(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return BelongsTo<EmailConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(EmailConnection::class, 'email_connection_id');
    }

    /** @return BelongsTo<BankStatementLine, $this> */
    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }
}
