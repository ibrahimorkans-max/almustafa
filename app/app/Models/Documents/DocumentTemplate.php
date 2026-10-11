<?php

declare(strict_types=1);

namespace App\Models\Documents;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A named template of one document type in one of the four layouts. Its look (header, footer,
 * terms, colours, signatures, default toggles) lives in append-only versions; every generated
 * document records the version that produced it.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $doc_type
 * @property string $name
 * @property string $layout classic|compact|detailed|minimal
 * @property bool $is_default
 * @property string $status
 * @property-read DocumentTemplateVersion|null $latest
 */
class DocumentTemplate extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const LAYOUTS = [
        'classic' => 'Classic', 'compact' => 'Compact', 'detailed' => 'Detailed (with breakdown)', 'minimal' => 'Minimal',
    ];

    protected $fillable = ['doc_type', 'name', 'layout', 'is_default', 'status'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'version' => 'integer'];
    }

    /** @return HasMany<DocumentTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentTemplateVersion::class, 'template_id');
    }

    /** @return HasOne<DocumentTemplateVersion, $this> */
    public function latest(): HasOne
    {
        return $this->hasOne(DocumentTemplateVersion::class, 'template_id')->orderByDesc('version_no');
    }
}
