<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Documents\RecordDocuments;
use App\Filament\Support\PrintButton;
use App\Support\Values\Money;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Every list of records in the system (each Filament resource) that this person may open, found by
 * words: "payments", "saudas", "employees", "leave"… The assistant uses it to list, search, show,
 * print and open the "new" form of any of them, so no feature is out of its reach.
 */
final class Screens
{
    /** Columns tried, in order, to find a record by what the person typed. */
    private const KEYS = ['number', 'code', 'registration_no', 'username', 'name', 'full_name', 'title', 'summary', 'description'];

    /** @var array<string, list<string>> */
    private static array $columns = [];

    /** @return list<class-string<resource>> resources this person may list */
    public static function resources(): array
    {
        $out = [];
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            try {
                if ($resource::canViewAny()) {
                    $out[] = $resource;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $out;
    }

    /** The resource best matching the words, or null. @return class-string<Resource>|null */
    public static function find(string $words): ?string
    {
        $wanted = array_values(array_filter(preg_split('/\W+/u', mb_strtolower($words)) ?: [], fn ($w) => mb_strlen($w) > 2
            && ! in_array($w, ['the', 'new', 'all', 'list', 'show', 'open', 'create', 'add', 'print', 'find', 'search', 'records', 'screen'], true)));
        $best = null;
        $bestScore = 0;
        foreach (self::resources() as $resource) {
            $hay = mb_strtolower(implode(' ', [$resource::getNavigationLabel(), $resource::getModelLabel(), $resource::getPluralModelLabel(), $resource::getSlug()]));
            $score = 0;
            foreach ($wanted as $w) {
                $stem = preg_replace('/(ies|es|s)$/u', '', $w) ?: $w;
                $score += str_contains($hay, $stem) ? 2 + (str_contains($hay, $w) ? 1 : 0) : 0;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$resource, $score];
            }
        }

        return $best;
    }

    /** @return list<string> */
    public static function columns(Model $model): array
    {
        return self::$columns[$model->getTable()] ??= Schema::getColumnListing($model->getTable());
    }

    /**
     * Records of a resource, newest first, optionally matching the words.
     *
     * @param  class-string<resource>  $resource
     * @return Builder<Model>
     */
    public static function query(string $resource, ?string $search = null): Builder
    {
        $query = $resource::getEloquentQuery();
        $model = $query->getModel();
        $columns = self::columns($model);
        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $keys = array_values(array_intersect(self::KEYS, $columns));
            $query->where(function (Builder $q) use ($keys, $like, $search, $model): void {
                foreach ($keys as $key) {
                    $q->orWhereRaw('LOWER('.$model->qualifyColumn($key).') LIKE ?', [$like]);
                }
                if (preg_match('/^[0-9a-f-]{8,36}$/i', $search)) {
                    $q->orWhere($model->qualifyColumn($model->getKeyName()), 'like', strtolower($search).'%');
                }
            });
        }
        foreach (['created_at', 'arrived_at', 'entry_date', 'payment_date', 'bill_date'] as $order) {
            if (in_array($order, $columns, true)) {
                return $query->latest($model->qualifyColumn($order));
            }
        }

        return $query;
    }

    /** A short label of a record: number / code / name. */
    public static function label(Model $record): string
    {
        foreach (['number', 'code', 'registration_no', 'username', 'name', 'full_name', 'title', 'summary'] as $key) {
            $value = array_key_exists($key, $record->getAttributes()) ? $record->getAttribute($key) : null;
            if (filled($value) && is_scalar($value)) {
                return (string) $value;
            }
        }

        return substr((string) $record->getKey(), 0, 8);
    }

    /** @return list<array{0: string, 1: string}> label / value pairs a person can read */
    public static function details(Model $record): array
    {
        $rows = [];
        foreach ($record->attributesToArray() as $key => $value) {
            if ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_index') || in_array($key, ['version', 'password', 'mfa_secret', 'remember_token'], true)) {
                continue;
            }
            $raw = $record->getAttribute($key);
            $text = match (true) {
                $raw === null || $raw === '' => null,
                str_ends_with($key, '_paise') && is_numeric($raw) => Money::paise((int) $raw)->format(),
                $raw instanceof CarbonInterface => $raw->timezone('Asia/Karachi')->format('d M Y H:i'),
                is_bool($raw) => $raw ? 'Yes' : 'No',
                is_array($raw) => mb_substr((string) json_encode($raw, JSON_UNESCAPED_UNICODE), 0, 200),
                is_scalar($raw) => mb_substr((string) $raw, 0, 200),
                default => null,
            };
            if ($text !== null) {
                $rows[] = [ucfirst(str_replace(['_paise', '_kg', '_'], [' (Rs)', ' (kg)', ' '], $key)), $text];
            }
        }

        return $rows;
    }

    /**
     * Links for a record: open it, and print it.
     *
     * @param  class-string<resource>  $resource
     * @return list<array{label: string, url: string}>
     */
    public static function links(string $resource, Model $record): array
    {
        $links = [];
        foreach (['view' => 'Open', 'edit' => 'Open'] as $page => $label) {
            if ($resource::hasPage($page)) {
                try {
                    $links[] = ['label' => $label.' '.self::label($record), 'url' => $resource::getUrl($page, ['record' => $record])];
                    break;
                } catch (Throwable) {
                    continue;
                }
            }
        }
        foreach (RecordDocuments::TYPES as $type => [$model]) {
            if ($record instanceof $model && PrintButton::allowed($type)) {
                return [...$links, ['label' => 'Print '.self::label($record), 'url' => PrintButton::url($type, $record)]];
            }
        }
        $links[] = ['label' => 'Print '.self::label($record), 'url' => route('filament.admin.print-record', ['resource' => self::key($resource), 'id' => $record->getKey()])];

        return $links;
    }

    /** A short, stable key for a resource in a URL (slugs may contain slashes). @param  class-string<resource>  $resource */
    public static function key(string $resource): string
    {
        return substr(md5($resource), 0, 12);
    }

    /** @return class-string<resource>|null */
    public static function byKey(string $key): ?string
    {
        foreach (self::resources() as $resource) {
            if (self::key($resource) === $key) {
                return $resource;
            }
        }

        return null;
    }
}
