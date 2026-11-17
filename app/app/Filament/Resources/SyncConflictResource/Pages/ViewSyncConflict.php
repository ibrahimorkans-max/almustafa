<?php

declare(strict_types=1);

namespace App\Filament\Resources\SyncConflictResource\Pages;

use App\Domain\Sync\ConflictResolver;
use App\Filament\Resources\SyncConflictResource;
use App\Models\SyncConflict;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ViewSyncConflict extends ViewRecord
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = SyncConflictResource::class;

    protected function getHeaderActions(): array
    {
        $note = [Textarea::make('note')->label(__('Reason for your decision'))->required()->maxLength(1000)];

        return [
            Action::make('apply')->label(__('Apply device data'))->color('warning')
                ->visible(fn (SyncConflict $record) => Gate::allows('resolve', $record))
                ->requiresConfirmation()
                ->modalDescription(__('The device values overwrite the current record (audited).'))
                ->form($note)
                ->action(fn (SyncConflict $record, array $data) => $this->resolve(fn (ConflictResolver $r) => $r->applyClient($record, $data['note']))),
            Action::make('dismiss')->label(__('Keep server data'))->color('gray')
                ->visible(fn (SyncConflict $record) => Gate::allows('resolve', $record))
                ->form($note)
                ->action(fn (SyncConflict $record, array $data) => $this->resolve(fn (ConflictResolver $r) => $r->dismiss($record, $data['note']))),
        ];
    }

    /** @param  callable(ConflictResolver): mixed  $callback */
    private function resolve(callable $callback): void
    {
        try {
            $callback(app(ConflictResolver::class));
        } catch (RuntimeException|ValidationException $e) {
            $message = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
            Notification::make()->title(__('Not resolved'))->body($message)->danger()->send();

            return;
        }

        Notification::make()->title(__('Review recorded'))->success()->send();
        $this->record->refresh();
    }
}
