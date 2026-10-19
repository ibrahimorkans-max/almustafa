<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Documents\RecordDocuments;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Model;

/**
 * "Print" on records that have a printed copy (RecordDocuments): a button on the record's page,
 * one on each table row, and a Print link in the message right after the record is saved, so
 * the slip or bill comes out without searching for it.
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
            ->visible(fn (Model $record) => self::allowed($type) && ($when === null || $when($record)));
    }

    /** @param  callable|null  $when  given the record; false hides the button */
    public static function row(string $type, ?callable $when = null): TableAction
    {
        return TableAction::make('print')->label('Print')->icon('heroicon-o-printer')->color('gray')
            ->url(fn (Model $record) => self::url($type, $record), shouldOpenInNewTab: true)
            ->visible(fn (Model $record) => self::allowed($type) && ($when === null || $when($record)));
    }

    /** The "saved" message with a Print link. */
    public static function saved(string $type, Model $record, string $title): Notification
    {
        return Notification::make()->success()->title($title)
            ->actions(self::allowed($type) ? [NotificationAction::make('print')->label('Print '.mb_strtolower(RecordDocuments::TYPES[$type][1]))
                ->url(self::url($type, $record), shouldOpenInNewTab: true)] : []);
    }
}
