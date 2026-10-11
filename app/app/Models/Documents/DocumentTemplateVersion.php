<?php

declare(strict_types=1);

namespace App\Models\Documents;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of a template's look. Append-only: a change is a new version.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $template_id
 * @property int $version_no
 * @property array<string, mixed> $settings
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read DocumentTemplate $template
 */
class DocumentTemplateVersion extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['template_id', 'version_no', 'settings', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'settings' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<DocumentTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }
}
