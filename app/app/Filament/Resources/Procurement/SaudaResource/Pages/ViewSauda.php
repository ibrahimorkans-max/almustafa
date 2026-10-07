<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\SaudaResource\Pages;

use App\Domain\Procurement\SaudaService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Procurement\SaudaResource;
use App\Models\Procurement\Sauda;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSauda extends ViewRecord
{
    protected static string $resource = SaudaResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;
        $reason = [Textarea::make('reason')->required()->maxLength(1000)];

        return [
            Action::make('submit')->label('Send for approval')->icon('heroicon-o-paper-airplane')
                ->visible(fn (Sauda $record) => $record->status === Sauda::STATUS_DRAFT && $can('deal.create'))
                ->requiresConfirmation()
                ->action(fn (Sauda $record) => $this->run(fn (SaudaService $s) => $s->submit($record), 'Sent for approval')),
            Action::make('approve')->color('success')->icon('heroicon-o-check')
                ->visible(fn (Sauda $record) => $record->status === Sauda::STATUS_PENDING && $can('deal.approve'))
                ->form([Textarea::make('note')->maxLength(1000)])
                ->requiresConfirmation()
                ->modalDescription('Approving freezes quantity, rate and all terms of this deal.')
                ->action(fn (Sauda $record, array $data) => $this->run(fn (SaudaService $s) => $s->approve($record, $data['note'] ?? null), 'Sauda confirmed')),
            Action::make('reject')->color('danger')->icon('heroicon-o-x-mark')
                ->visible(fn (Sauda $record) => $record->status === Sauda::STATUS_PENDING && $can('deal.approve'))
                ->form($reason)
                ->action(fn (Sauda $record, array $data) => $this->run(fn (SaudaService $s) => $s->reject($record, $data['reason']), 'Sent back to draft')),
            Action::make('cancel')->color('danger')->icon('heroicon-o-no-symbol')
                ->visible(fn (Sauda $record) => ! in_array($record->status, [Sauda::STATUS_COMPLETED, Sauda::STATUS_CANCELLED, Sauda::STATUS_EXPIRED], true) && $can('deal.approve'))
                ->form($reason)
                ->requiresConfirmation()
                ->action(fn (Sauda $record, array $data) => $this->run(fn (SaudaService $s) => $s->cancel($record, $data['reason']), 'Sauda cancelled')),
        ];
    }

    /** @param  callable(SaudaService): Sauda  $callback */
    private function run(callable $callback, string $success): void
    {
        try {
            $this->record = $callback(app(SaudaService::class));
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($success)->success()->send();
    }
}
