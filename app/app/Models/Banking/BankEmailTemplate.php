<?php

declare(strict_types=1);

namespace App\Models\Banking;

use App\Domain\Audit\Auditable;
use App\Support\Eloquent\HasOptimisticLock;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * How one bank's notification e-mail is read: regular expressions over the sender, subject and text.
 * Set by the product owner for every customer (not tied to a mill). The amount pattern must have a
 * named group "amount"; the others a group "value" (or their first group).
 *
 * @property string $id
 * @property string $bank_name
 * @property string $sender_pattern
 * @property string|null $subject_pattern
 * @property string $amount_pattern
 * @property string|null $credit_pattern
 * @property string|null $debit_pattern
 * @property string|null $reference_pattern
 * @property string|null $account_pattern
 * @property string|null $counterparty_pattern
 * @property string|null $counterparty_account_pattern
 * @property string|null $date_pattern
 * @property string|null $date_format
 * @property bool $active
 */
class BankEmailTemplate extends Model
{
    use Auditable;
    use HasOptimisticLock;
    use HasUuids;

    public const PATTERNS = ['sender_pattern', 'subject_pattern', 'amount_pattern', 'credit_pattern', 'debit_pattern', 'reference_pattern',
        'account_pattern', 'counterparty_pattern', 'counterparty_account_pattern', 'date_pattern'];

    protected $fillable = ['bank_name', 'sender_pattern', 'subject_pattern', 'amount_pattern', 'credit_pattern', 'debit_pattern', 'reference_pattern',
        'account_pattern', 'counterparty_pattern', 'counterparty_account_pattern', 'date_pattern', 'date_format', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }
}
