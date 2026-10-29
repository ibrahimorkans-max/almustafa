<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Masters\CodeDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of the Code Directory: read-only, built by CodeDirectory::query() from the records themselves.
 *
 * @property string $module
 * @property string $code
 * @property string|null $name
 * @property string $record_id
 * @property Carbon|null $created_at
 */
class CodeDirectoryEntry extends Model
{
    protected $table = 'code_directory';

    protected $primaryKey = 'record_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function moduleLabel(): string
    {
        return CodeDirectory::MODULES[$this->module] ?? $this->module;
    }

    /** Never written: the codes live on their own records. */
    public function save(array $options = []): bool
    {
        return false;
    }
}
