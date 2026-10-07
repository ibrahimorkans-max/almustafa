<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionBatchResource\Pages;

use App\Domain\Production\ProductionService;
use App\Filament\Resources\Inventory\RequisitionResource;
use App\Filament\Resources\Production\ProductionBatchResource;
use App\Filament\Support\RunsDomainActions;
use App\Filament\Support\Units;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Production\ProductionBatch;
use App\Models\Production\ProductionLog;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

/**
 * Hourly logging for a batch. Each log moves stock at once; a mistake is corrected with a
 * reversing log and a reason. Closing freezes the yield.
 */
class ViewProductionBatch extends ViewRecord
{
    use RunsDomainActions;

    protected static string $resource = ProductionBatchResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;
        $open = fn (ProductionBatch $b) => $b->isOpen();
        $godowns = fn () => RequisitionResource::godownOptions();
        $period = [
            DateTimePicker::make('period_start')->label('From')->seconds(false)->required()->default(now()->subHour()->startOfHour()),
            DateTimePicker::make('period_end')->label('To')->seconds(false)->required()->default(now()->startOfHour())->after('period_start'),
        ];
        $service = fn () => app(ProductionService::class);
        $at = fn (array $data, string $key) => CarbonImmutable::parse($data[$key]);

        return [
            Action::make('input')->label('Log paddy fed')->icon('heroicon-o-arrow-down-on-square')
                ->visible(fn (ProductionBatch $b) => $open($b) && $can('production.log'))
                ->modalDescription('Takes the paddy out of the godown now. For stores-controlled issue use a requisition.')
                ->form([
                    Select::make('godown_id')->label('From godown')->required()->options($godowns),
                    TextInput::make('quantity_kg')->label('Quantity (kg)')->required()->regex(Units::DECIMAL_3),
                    ...$period,
                    Textarea::make('note')->maxLength(1000),
                ])
                ->action(fn (ProductionBatch $b, array $data) => $this->runDomain(fn () => $service()->recordInput(
                    $b, Godown::query()->findOrFail($data['godown_id']), (string) $data['quantity_kg'],
                    $at($data, 'period_start'), $at($data, 'period_end'), $data['note'] ?? null,
                ), 'Input logged')),
            Action::make('output')->label('Log output')->icon('heroicon-o-arrow-up-on-square')->color('success')
                ->visible(fn (ProductionBatch $b) => $open($b) && $can('production.log'))
                ->form([
                    Select::make('product_id')->label('Rice / by-product')->required()
                        ->options(fn () => Product::query()->whereIn('category', ProductionService::OUTPUT_CATEGORIES)->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                    Select::make('godown_id')->label('Into godown')->required()->options($godowns),
                    TextInput::make('quantity_kg')->label('Quantity (kg)')->required()->regex(Units::DECIMAL_3),
                    ...$period,
                    Textarea::make('note')->maxLength(1000),
                ])
                ->action(fn (ProductionBatch $b, array $data) => $this->runDomain(fn () => $service()->recordOutput(
                    $b, Product::query()->findOrFail($data['product_id']), Godown::query()->findOrFail($data['godown_id']), (string) $data['quantity_kg'],
                    $at($data, 'period_start'), $at($data, 'period_end'), $data['note'] ?? null,
                ), 'Output logged')),
            Action::make('correct')->label('Correct a log')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                ->visible(fn (ProductionBatch $b) => $open($b) && $can('production.log'))
                ->modalDescription('Adds a reversing log and moves the stock back. The original stays on record.')
                ->form([
                    Select::make('log_id')->label('Log to reverse')->required()
                        ->options(fn (ProductionBatch $record) => ProductionLog::query()->where('batch_id', $record->id)->whereNull('reverses_id')
                            ->whereNotIn('id', ProductionLog::query()->where('batch_id', $record->id)->whereNotNull('reverses_id')->select('reverses_id'))
                            ->with('product')->orderByDesc('period_start')->get()
                            ->mapWithKeys(fn (ProductionLog $l) => [$l->id => "{$l->period_start->format('d M H:i')} · {$l->kind} · {$l->product->name} · {$l->quantity_kg} kg"])),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(1000),
                ])
                ->action(fn (array $data) => $this->runDomain(
                    fn () => $service()->reverse(ProductionLog::query()->findOrFail($data['log_id']), $data['reason']),
                    'Log corrected',
                )),
            Action::make('close')->label('Close batch')->icon('heroicon-o-lock-closed')->color('danger')
                ->visible(fn (ProductionBatch $b) => $open($b) && $can('batch.manage'))
                ->requiresConfirmation()
                ->modalDescription('Freezes the yield against the outturn standards. No more logs can be added.')
                ->form([Textarea::make('notes')->maxLength(2000)])
                ->action(fn (ProductionBatch $b, array $data) => $this->runDomain(fn () => $service()->close($b, $data['notes'] ?? null), 'Batch closed')),
        ];
    }
}
