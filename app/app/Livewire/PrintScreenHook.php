<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\ScreenPrinter;
use Filament\Pages\Page;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Str;
use Livewire\ComponentHook;

/**
 * Answers the "Print" button that every list and record screen gets (render hook in
 * AdminPanelProvider): `wire:click="printScreen"` on any Filament page, without each page
 * declaring it. The PDF is downloaded straight away.
 */
final class PrintScreenHook extends ComponentHook
{
    public const METHOD = 'printScreen';

    /** Which pages get the button: lists with a table, and record pages. */
    public static function supports(string $pageClass): bool
    {
        if (! is_subclass_of($pageClass, Page::class)) {
            return false;
        }

        return is_subclass_of($pageClass, ViewRecord::class) || is_subclass_of($pageClass, EditRecord::class) || is_subclass_of($pageClass, HasTable::class);
    }

    /** @param  array<int, mixed>  $params */
    public function call(string $method, array $params, callable $returnEarly): void
    {
        if ($method !== self::METHOD || ! self::supports($this->component::class)) {
            return;
        }
        $page = $this->component;
        $title = method_exists($page, 'getTitle') ? strip_tags((string) $page->getTitle()) : 'Report';
        $printer = app(ScreenPrinter::class);
        $pdf = $page instanceof ViewRecord || $page instanceof EditRecord ? $printer->record($page, $title) : $printer->table($page, $title);
        app(AuditLogger::class)->record('document.printed', null, null, null, ['screen' => $page::class, 'title' => $title]);

        $name = Str::slug($title.' '.now('Asia/Karachi')->format('Y-m-d H-i')).'.pdf';
        $returnEarly(response()->streamDownload(fn () => print $pdf, $name, ['Content-Type' => 'application/pdf']));
    }
}
