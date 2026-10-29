<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Person;
use App\Models\StoredFile;
use App\Support\BlindIndex;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use App\Support\Values\PakistanIds;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An employee is a person (name, phone, e-mail live on `persons`) employed by one entity. Their
 * ERP login, if any, is the login account of the same person in the same entity.
 *
 * CNIC, NTN, bank account, date of birth and address are encrypted; screens show the last four
 * digits unless the viewer holds hr.employee.sensitive.view (each reveal is audited).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $person_id
 * @property string|null $photo_file_id
 * @property string|null $department_id
 * @property string|null $designation_id
 * @property string|null $shift_id
 * @property string $employment_type
 * @property string $status
 * @property Carbon $joining_date
 * @property string|null $date_of_birth
 * @property string|null $address
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property string|null $cnic
 * @property string|null $cnic_index
 * @property string|null $cnic_last4
 * @property string|null $eobi_number
 * @property string|null $pessi_number
 * @property bool $eobi_applicable
 * @property bool $pessi_applicable
 * @property string|null $ntn
 * @property string|null $ntn_index
 * @property string|null $ntn_last4
 * @property string|null $bank_account_number
 * @property string|null $bank_account_index
 * @property string|null $bank_account_last4
 * @property string|null $bank_code
 * @property string|null $bank_name
 * @property string|null $bank_account_holder
 * @property string|null $bank_approval_request_id
 * @property int $notice_period_days
 * @property Carbon|null $notice_given_on
 * @property Carbon|null $last_working_day
 * @property string|null $exit_reason
 * @property Carbon|null $exited_on
 * @property int $version
 * @property Carbon|null $archived_at
 * @property-read Person $person
 * @property-read Department|null $department
 * @property-read Designation|null $designation
 * @property-read Shift|null $shift
 * @property-read StoredFile|null $photo
 * @property-read Collection<int, EmployeeDocument> $documents
 * @property-read Collection<int, SalaryStructure> $salaryStructures
 * @property-read Collection<int, ClearanceItem> $clearanceItems
 */
class Employee extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const TYPES = ['permanent' => 'Permanent', 'contract' => 'Contract', 'daily_wage' => 'Daily wage', 'piece_rate' => 'Piece rate'];

    public const ACTIVE = 'active';

    public const ON_NOTICE = 'on_notice';

    public const EXITED = 'exited';

    protected $fillable = [
        'person_id', 'photo_file_id', 'department_id', 'designation_id', 'shift_id', 'employment_type', 'joining_date', 'date_of_birth',
        'address', 'emergency_contact_name', 'emergency_contact_phone', 'cnic', 'ntn', 'notice_period_days',
        'eobi_number', 'pessi_number', 'eobi_applicable', 'pessi_applicable', 'contractor_party_id',
    ];

    /** Same defaults as the table, so a new model knows them before it is reloaded. */
    protected $attributes = ['status' => self::ACTIVE, 'notice_period_days' => 30];

    /** Never serialised: bank details and identifiers leave the model only through masked accessors. */
    protected $hidden = ['cnic', 'cnic_index', 'ntn', 'ntn_index', 'bank_account_number', 'bank_account_index', 'date_of_birth', 'address'];

    /** @var list<string> recorded in the audit log as ****1234 */
    protected array $auditMasked = ['cnic', 'ntn', 'bank_account_number'];

    /** @var list<string> recorded only as "[changed]" */
    protected array $auditSecret = ['date_of_birth', 'address', 'cnic_index', 'ntn_index', 'bank_account_index'];

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'date_of_birth' => 'encrypted',
            'address' => 'encrypted',
            'cnic' => 'encrypted',
            'ntn' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'notice_period_days' => 'integer',
            'notice_given_on' => 'date',
            'last_working_day' => 'date',
            'exited_on' => 'date',
            'version' => 'integer',
            'eobi_applicable' => 'boolean',
            'pessi_applicable' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('not_archived', fn (Builder $q) => $q->whereNull('employees.archived_at'));

        static::saving(function (Employee $e): void {
            if ($e->isDirty('cnic')) {
                $e->cnic = self::digits($e->cnic);
                $e->cnic_index = BlindIndex::of($e->cnic, 'cnic');
                $e->cnic_last4 = $e->cnic !== null ? substr($e->cnic, -4) : null;
            }
            if ($e->isDirty('ntn')) {
                $e->ntn = self::digits($e->ntn);
                $e->ntn_index = BlindIndex::of($e->ntn, 'ntn');
                $e->ntn_last4 = $e->ntn !== null ? substr($e->ntn, -4) : null;
            }
            if ($e->isDirty('bank_account_number')) {
                // The salary account is held as its IBAN; the bank code is part of it.
                $e->bank_account_number = PakistanIds::normalizeIban($e->bank_account_number);
                $e->bank_account_index = BlindIndex::of($e->bank_account_number, 'bank_account');
                $e->bank_account_last4 = $e->bank_account_number !== null ? substr($e->bank_account_number, -4) : null;
                $e->bank_code = $e->bank_account_number !== null && strlen($e->bank_account_number) === 24 ? PakistanIds::bankCodeOfIban($e->bank_account_number) : null;
            }
        });
    }

    private static function digits(?string $value): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $d === '' ? null : $d;
    }

    public static function maskedTail(?string $last4, int $groups = 3): string
    {
        return $last4 === null ? '—' : str_repeat('XXXX ', $groups).$last4;
    }

    public function maskedCnic(): string
    {
        return $this->cnic_last4 === null ? '—' : 'XXXXX-XXXX'.substr($this->cnic_last4, 0, 3).'-'.substr($this->cnic_last4, 3);
    }

    public function maskedNtn(): string
    {
        return $this->ntn_last4 === null ? '—' : 'XXXX'.$this->ntn_last4;
    }

    public function maskedBankAccount(): string
    {
        return $this->bank_account_last4 === null ? '—' : 'PK** **** **** **** '.$this->bank_account_last4.' · '.$this->bank_code;
    }

    public function name(): string
    {
        return $this->person->full_name ?? $this->code;
    }

    /** The salary structure in force on the given day (latest effective date; the newest version wins on a tie). */
    public function salaryOn(CarbonInterface $day): ?SalaryStructure
    {
        return $this->salaryStructures()->with('lines.component')
            ->where('effective_from', '<=', $day->toDateString())
            // Ordered UUIDs break ties within the same second (timestamps are stored to the second).
            ->orderByDesc('effective_from')->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /** @return Collection<int, LoginAccount> */
    public function loginAccounts(): Collection
    {
        return LoginAccount::query()->with('role')->where('person_id', $this->person_id)->where('entity_id', $this->entity_id)->get();
    }

    /** @return BelongsTo<Party, $this> the labour contractor who supplies this worker, if any */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'contractor_party_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Designation, $this> */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<StoredFile, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'photo_file_id');
    }

    /** @return HasMany<EmployeeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /** @return HasMany<SalaryStructure, $this> */
    public function salaryStructures(): HasMany
    {
        return $this->hasMany(SalaryStructure::class);
    }

    /** @return HasMany<LeaveLedgerEntry, $this> */
    public function leaveLedger(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    /** @return HasMany<ClearanceItem, $this> */
    public function clearanceItems(): HasMany
    {
        return $this->hasMany(ClearanceItem::class);
    }
}
