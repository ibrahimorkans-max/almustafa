<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Audit\Auditable;
use App\Domain\Security\RecordsEntryLocation;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\StoredFile;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One page of the daily cash book (roznamcha): scanned or typed, read by OCR, corrected and checked by
 * a person, then posted once (each line a cash receipt or payment voucher). The original scan is kept.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property Carbon $entry_date
 * @property string $source camera | scanner | pdf | image | manual
 * @property string|null $stored_file_id
 * @property string|null $ocr_text
 * @property string|null $ocr_engine
 * @property string $status draft | verified | posted
 * @property string|null $cash_ledger_id
 * @property string|null $note
 * @property string|null $created_by
 * @property string|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $posted_by
 * @property Carbon|null $posted_at
 * @property-read Collection<int, RoznamchaLine> $lines
 * @property-read StoredFile|null $scan
 * @property array{lat: float, lng: float, accuracy_m: int|null, at: string}|null $captured_location where it was entered (EntryLocation)
 */
class RoznamchaPage extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use RecordsEntryLocation;
    use TracksCreator;

    public const SOURCES = ['camera' => 'Mobile camera', 'scanner' => 'Scanner', 'pdf' => 'PDF', 'image' => 'Image', 'manual' => 'Typed by hand'];

    public const STATUS_LABELS = ['draft' => 'To check', 'verified' => 'Checked', 'posted' => 'Posted'];

    protected $fillable = ['entry_date', 'source', 'stored_file_id', 'ocr_text', 'ocr_engine', 'cash_ledger_id', 'note'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'verified_at' => 'datetime', 'posted_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return HasMany<RoznamchaLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RoznamchaLine::class)->orderBy('position');
    }

    /** @return BelongsTo<StoredFile, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function cashLedger(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'cash_ledger_id');
    }
}
