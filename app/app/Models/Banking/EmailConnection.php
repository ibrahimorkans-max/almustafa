<?php

declare(strict_types=1);

namespace App\Models\Banking;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\LedgerAccount;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A mailbox a mill connected to read its bank's notification e-mails. The credentials (OAuth tokens,
 * or IMAP host and app password) are kept encrypted and never shown again; only reading is asked for.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $provider google | microsoft | imap
 * @property string $email
 * @property string|null $ledger_account_id
 * @property array<string, mixed>|null $credentials
 * @property string $status pending | connected | error | disconnected
 * @property int $sync_minutes
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_success_at
 * @property string|null $last_error
 * @property string|null $oauth_state
 * @property-read LedgerAccount|null $bankLedger
 */
class EmailConnection extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const PROVIDERS = ['google' => 'Gmail / Google Workspace', 'microsoft' => 'Outlook / Microsoft 365', 'imap' => 'Other (IMAP with an app password)'];

    public const STATUS_LABELS = ['pending' => 'Waiting for sign-in', 'connected' => 'Connected', 'error' => 'Error', 'disconnected' => 'Disconnected'];

    protected $fillable = ['provider', 'email', 'ledger_account_id', 'sync_minutes'];

    protected $hidden = ['credentials', 'oauth_state'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'sync_minutes' => 30];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', 'last_synced_at' => 'datetime', 'last_success_at' => 'datetime',
            'sync_minutes' => 'integer', 'version' => 'integer',
        ];
    }

    /** Connected, and its interval (at least 5 minutes) has passed since the last reading. */
    public function isDue(): bool
    {
        return in_array($this->status, ['connected', 'error'], true) // a failed reading is tried again
            && ($this->last_synced_at === null || $this->last_synced_at->lte(now()->subMinutes(max(5, $this->sync_minutes))));
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function bankLedger(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return HasMany<EmailSyncRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(EmailSyncRun::class);
    }
}
