<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes created/updated/deleted events with old and new values.
 *
 * Models may declare:
 *   protected array $auditMasked  = ['pan'];             // value recorded as ****1234
 *   protected array $auditSecret  = ['password'];        // recorded only as "[changed]"
 *   protected array $auditIgnored = ['last_login_at'];   // noise; never triggers an audit row
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            $new = $model->auditValues(array_keys($model->getAttributes()), fn (string $k) => $model->getAttribute($k));
            app(AuditLogger::class)->record($model->auditPrefix().'.created', $model, null, $new);
        });

        static::updated(function (self $model): void {
            $keys = array_diff(array_keys($model->getChanges()), $model->auditIgnoredKeys());
            if ($keys === []) {
                return;
            }

            $old = $model->auditValues($keys, fn (string $k) => $model->getOriginal($k));
            $new = $model->auditValues($keys, fn (string $k) => $model->getAttribute($k));
            app(AuditLogger::class)->record($model->auditPrefix().'.updated', $model, $old, $new);
        });

        static::deleted(function (self $model): void {
            $old = $model->auditValues(array_keys($model->getAttributes()), fn (string $k) => $model->getAttribute($k));
            app(AuditLogger::class)->record($model->auditPrefix().'.deleted', $model, $old, null);
        });
    }

    public function auditPrefix(): string
    {
        return Str::singular($this->getTable());
    }

    /** @return list<string> */
    public function auditIgnoredKeys(): array
    {
        $own = property_exists($this, 'auditIgnored') ? $this->auditIgnored : [];

        return [...$own, 'updated_at', 'created_at', 'version'];
    }

    /**
     * @param  list<string>  $keys
     * @param  callable(string): mixed  $value
     * @return array<string, mixed>
     */
    public function auditValues(array $keys, callable $value): array
    {
        $masked = property_exists($this, 'auditMasked') ? $this->auditMasked : [];
        $secret = property_exists($this, 'auditSecret') ? $this->auditSecret : [];
        $ignored = $this->auditIgnoredKeys();

        $out = [];
        foreach ($keys as $key) {
            if (in_array($key, $ignored, true) && ! in_array($key, ['created_at'], true)) {
                continue;
            }
            $v = $value($key);
            $out[$key] = match (true) {
                in_array($key, $secret, true) => $v === null ? null : '[set]',
                in_array($key, $masked, true) => self::mask($v),
                $v instanceof \DateTimeInterface => $v->format('Y-m-d\TH:i:s.uP'),
                $v instanceof \BackedEnum => $v->value,
                is_scalar($v) || $v === null || is_array($v) => $v,
                default => (string) json_encode($v),
            };
        }

        return $out;
    }

    private static function mask(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;

        return str_repeat('*', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }
}
