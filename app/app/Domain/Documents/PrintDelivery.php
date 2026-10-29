<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * "Print" opens the browser's print dialog straight away, without saving a file first: the PDF is kept
 * for ten minutes for the person who asked (a random key, nobody else can open it) and loaded in a hidden
 * frame of the page, which calls print() (public/js/print-frame.js). A phone opens it in a new tab instead.
 * "Download PDF" stays a separate button wherever a file is wanted.
 */
final class PrintDelivery
{
    private const TTL_MINUTES = 10;

    /** Keeps the file for the signed-in person and returns the address the print frame loads. */
    public static function stash(string $bytes, string $name, string $mime = 'application/pdf'): string
    {
        $key = Str::random(40);
        Cache::put(self::cacheKey($key), [
            'user' => (string) Filament::auth()->id(), 'name' => $name, 'mime' => $mime, 'bytes' => base64_encode($bytes),
        ], now()->addMinutes(self::TTL_MINUTES));

        return route('filament.admin.print-job', ['key' => $key]);
    }

    /** Sends the file to the print dialog of the page that asked. */
    public static function toPrinter(Component $page, string $bytes, string $name): void
    {
        $page->dispatch('rm-print', url: self::stash($bytes, $name));
    }

    /** @return array{name: string, mime: string, bytes: string}|null the file, only for the person who asked */
    public static function take(string $key): ?array
    {
        $job = Cache::get(self::cacheKey($key));
        if (! is_array($job) || $job['user'] !== (string) Filament::auth()->id()) {
            return null;
        }

        return ['name' => (string) $job['name'], 'mime' => (string) $job['mime'], 'bytes' => (string) base64_decode((string) $job['bytes'], true)];
    }

    private static function cacheKey(string $key): string
    {
        return 'print-job:'.hash('sha256', $key);
    }
}
