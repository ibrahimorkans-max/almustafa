<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ViewField;

/**
 * Where an entry was made (Phase 4 location). When the mill switched on "Record location with entries"
 * (Security → Sign-in rules), the entry forms ask the browser for its position (the browser asks the
 * person; nothing is recorded if they refuse) and the record keeps it in `captured_location`:
 * {lat, lng, accuracy_m, at}. Nothing is ever asked or kept when the setting is off.
 *
 * A form adds EntryLocation::fields(); its page calls EntryLocation::hold($data) before saving; a model
 * using RecordsEntryLocation takes it when it is created.
 */
final class EntryLocation
{
    /** @var array{lat: float, lng: float, accuracy_m: int|null, at: string}|null */
    private static ?array $held = null;

    public static function enabled(): bool
    {
        $entityId = app(TenantContext::class)->entityId();

        return $entityId !== null && app(SettingsService::class)->get(SettingsService::RECORD_LOCATION, $entityId) === 'on';
    }

    /** @return list<Component> */
    public static function fields(): array
    {
        return [
            Hidden::make('loc_lat')->dehydrated(),
            Hidden::make('loc_lng')->dehydrated(),
            Hidden::make('loc_acc')->dehydrated(),
            ViewField::make('loc_probe')->hiddenLabel()->view('filament.forms.entry-location')->dehydrated(false)
                ->visible(fn () => self::enabled())->columnSpanFull(),
        ];
    }

    /**
     * Keeps the position sent with the form for the record about to be created (only when the mill records it).
     *
     * @param  array<string, mixed>  $data
     */
    public static function hold(array $data): void
    {
        self::$held = null;
        $lat = $data['loc_lat'] ?? null;
        $lng = $data['loc_lng'] ?? null;
        if (! self::enabled() || ! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return;
        }
        self::$held = ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6),
            'accuracy_m' => is_numeric($data['loc_acc'] ?? null) ? (int) $data['loc_acc'] : null, 'at' => now()->toIso8601ZuluString()];
    }

    /** @return array{lat: float, lng: float, accuracy_m: int|null, at: string}|null the held position, once */
    public static function take(): ?array
    {
        $held = self::$held;
        self::$held = null;

        return $held;
    }

    /** "31.5204, 74.3587 (±12 m)" with an OpenStreetMap link, for record pages. */
    /** @param  array<string, mixed>|null  $location */
    public static function label(?array $location): ?string
    {
        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return null;
        }

        return sprintf('%.5f, %.5f', $location['lat'], $location['lng']).(isset($location['accuracy_m']) ? ' (±'.$location['accuracy_m'].' m)' : '');
    }

    /** @param  array<string, mixed>|null  $location */
    public static function mapUrl(?array $location): ?string
    {
        return is_array($location) && isset($location['lat'], $location['lng'])
            ? sprintf('https://www.openstreetmap.org/?mlat=%F&mlon=%F#map=18/%F/%F', $location['lat'], $location['lng'], $location['lat'], $location['lng'])
            : null;
    }
}
