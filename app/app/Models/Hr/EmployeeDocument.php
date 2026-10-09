<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\StoredFile;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ID proof, contract, bank proof ... in private, content-addressed storage. Never deleted: a newer
 * copy supersedes the old one, which stays in the history.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property string $kind
 * @property string $title
 * @property string $file_id
 * @property Carbon|null $valid_until
 * @property string $status active|superseded
 * @property string|null $superseded_by
 * @property Carbon $created_at
 * @property-read Employee $employee
 * @property-read StoredFile $file
 */
class EmployeeDocument extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const KINDS = [
        'aadhaar' => 'Aadhaar card', 'pan' => 'PAN card', 'id_proof' => 'Other ID proof', 'contract' => 'Contract / appointment letter',
        'bank_proof' => 'Bank proof (cheque / passbook)', 'photo' => 'Photo', 'other' => 'Other',
    ];

    protected $fillable = ['employee_id', 'kind', 'title', 'file_id', 'valid_until', 'status', 'superseded_by'];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'version' => 'integer'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<StoredFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
