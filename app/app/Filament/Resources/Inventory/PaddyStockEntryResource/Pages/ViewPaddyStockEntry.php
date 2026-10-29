<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\PaddyStockEntryResource\Pages;

use App\Domain\Inventory\PaddyStockEntries;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Inventory\PaddyStockEntryResource;
use App\Models\Inventory\PaddyStockEntry;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPaddyStockEntry extends ViewRecord
{
    protected static string $resource = PaddyStockEntryResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn () => Filament::auth()->user()?->can('stock.adjust') ?? false;

        return [
            Action::make('post')->label('Post to stock')->icon('heroicon-o-check')->color('success')
                ->visible(fn (PaddyStockEntry $record) => $record->status === PaddyStockEntry::DRAFT && $can())
                ->requiresConfirmation()->modalDescription('Adds the accepted weight to the godown\'s stock.')
                ->action(fn (PaddyStockEntry $record) => $this->run(fn (PaddyStockEntries $s) => $s->post($record), 'Posted to stock')),
            Action::make('cancel')->label('Cancel draft')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (PaddyStockEntry $record) => $record->status === PaddyStockEntry::DRAFT && $can())
                ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                ->action(fn (PaddyStockEntry $record, array $data) => $this->run(fn (PaddyStockEntries $s) => $s->cancel($record, (string) $data['reason']), 'Draft cancelled')),
        ];
    }

    /** @param  callable(PaddyStockEntries): PaddyStockEntry  $callback */
    private function run(callable $callback, string $success): void
    {
        try {
            $this->record = $callback(app(PaddyStockEntries::class));
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($success)->success()->send();
    }
}
