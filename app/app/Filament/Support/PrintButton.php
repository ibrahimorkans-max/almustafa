<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Documents\PrintDelivery;
use App\Domain\Documents\RecordDocuments;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * "Print" on records that have a printed copy (RecordDocuments): a button on the record's page,
 * one on each table row, and a Print link in the message right after the record is saved, so
 * the slip or bill comes out without searching for it. The buttons open the print dialog on the
 * printed copy (public/js/print-frame.js); the link still opens it in a new tab where scripts are off.
 */
final class PrintButton
{
    public static function url(string $type, Model $record): string
    {
        return route('filament.admin.print', ['type' => $type, 'id' => $record->getKey()]);
    }

    public static function allowed(string $type): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission(RecordDocuments::TYPES[$type][2]);
    }

    /** @param  callable|null  $when  given the record; false hides the button */
    public static function header(string $type, ?callable $when = null): Action
    {
        return Action::make('print')->label('Print '.mb_strtolower(RecordDocuments::TYPES[$type][1]))->icon('heroicon-o-printer')->color('gray')
            ->url(fn (Model $record) => self::url($type, $record), shouldOpenInNewTab: true)
            ->extraAttributes(fn (Model $record) => ['data-rm-print' => self::url($type, $record)])
            ->visible(fn (Model $record) => self::allowed($type) && ($when === null || $when($record)));
    }

    /** @param  callable|null  $when  given the record; false hides the button */
    public static function row(string $type, ?callable $when = null): TableAction
    {
        return TableAction::make('print')->label('Print')->icon('heroicon-o-printer')->color('gray')
            ->url(fn (Model $record) => self::url($type, $record), shouldOpenInNewTab: true)
            ->extraAttributes(fn (Model $record) => ['data-rm-print' => self::url($type, $record)])
            ->visible(fn (Model $record) => self::allowed($type) && ($when === null || $when($record)));
    }

    /** The "saved" message with a Print link. */
    public static function saved(string $type, Model $record, string $title): Notification
    {
        return Notification::make()->success()->title($title)
            ->actions(self::allowed($type) ? [NotificationAction::make('print')->label('Print '.mb_strtolower(RecordDocuments::TYPES[$type][1]))
                ->url(self::url($type, $record), shouldOpenInNewTab: true)] : []);
    }

    /**
     * A "Print" button for a PDF made on the spot (slip, invoice, voucher): the print dialog opens on it
     * (PrintDelivery), nothing is saved. Sits beside the record's "Download PDF" button.
     *
     * @template TRecord of Model
     *
     * @param  callable(TRecord): array{0: string, 1: string}  $pdf  the record → [PDF bytes, file name]
     */
    public static function dialog(string $name, string $label, callable $pdf): Action
    {
        return Action::make($name)->label($label)->icon('heroicon-o-printer')->color('gray')
            ->action(function (Model $record, Component $livewire) use ($pdf): void {
                [$bytes, $file] = $pdf($record);
                PrintDelivery::toPrinter($livewire, $bytes, $file);
            });
    }
}
