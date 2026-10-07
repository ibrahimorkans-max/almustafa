<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global permission catalog row (synced from App\Domain\Access\PermissionCatalog).
 *
 * @property string $code
 * @property string $module
 * @property string $description
 * @property bool $is_sensitive
 */
class Permission extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['code', 'module', 'description', 'is_sensitive'];

    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean'];
    }
}
