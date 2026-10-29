<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Domain\Audit\Auditable;
use App\Domain\Masters\MasterCodes;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A paddy variety the mill buys (Super Basmati, 1121, IRRI-6 …). Its code (PV-1121-0001) is made once
 * and kept when the variety is renamed. One name per mill: "super basmati" and "Super  Basmati" are the
 * same variety. A variety is deactivated, never deleted, so old saudas and receipts keep it.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string $name_key
 * @property string $category
 * @property string|null $description
 * @property string $unit
 * @property string $status
 * @property Carbon $created_at
 */
class PaddyVariety extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const CATEGORIES = ['basmati' => 'Basmati', 'non_basmati' => 'Non-basmati', 'hybrid' => 'Hybrid', 'other' => 'Other'];

    public const UNITS = ['kg' => 'kg', 'maund' => 'Maund (40 kg)', 'bag' => 'Bag'];

    protected $fillable = ['code', 'name', 'category', 'description', 'unit', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'category' => 'basmati', 'unit' => 'kg'];

    protected static function booted(): void
    {
        static::saving(function (PaddyVariety $v): void {
            $v->name = trim((string) preg_replace('/\s+/', ' ', (string) $v->name));
            $v->name_key = self::keyFor($v->name);
            $clash = static::query()->where('name_key', $v->name_key)->when($v->exists, fn (Builder $q) => $q->whereKeyNot($v->id))->exists();
            if ($clash) {
                throw new BusinessRuleViolation("There is already a variety named \"{$v->name}\".");
            }
        });
        static::creating(fn (PaddyVariety $v) => app(MasterCodes::class)->assign($v));
        // The code is permanent.
        static::updating(function (PaddyVariety $v): void {
            if ($v->isDirty('code')) {
                $v->code = (string) $v->getOriginal('code');
            }
        });
    }

    public static function keyFor(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    /** @return array<string, string> active varieties, "CODE — Name" */
    public static function options(): array
    {
        return static::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (PaddyVariety $v) => [$v->id => $v->code.' — '.$v->name])->all();
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
