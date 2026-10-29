<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockCountResource\Pages;

use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Inventory\StockCountService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Inventory\RequisitionResource;
use App\Filament\Resources\Inventory\StockCountResource;
use App\Filament\Support\ProductSelect;
use App\Filament\Support\Units;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListStockCounts extends ListRecords
{
    protected static string $resource = StockCountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('count')->label('Record physical count')->icon('heroicon-o-plus')
                ->visible(fn () => Filament::auth()->user()?->can('stock.adjust') ?? false)
                ->modalDescription('The books figure is taken at this moment. Any difference is sent to a second person for approval before it is booked.')
                ->form([
                    ProductSelect::make('product_id', stockItemsOnly: true)->required(),
                    Select::make('godown_id')->label('Godown')->required()->options(fn () => RequisitionResource::godownOptions()),
                    TextInput::make('counted_kg')->label('Counted (kg)')->required()->regex(Units::DECIMAL_3),
                    Textarea::make('note')->label('How it was counted')->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        $count = app(StockCountService::class)->record(
                            Product::query()->findOrFail($data['product_id']),
                            Godown::query()->findOrFail($data['godown_id']),
                            (string) $data['counted_kg'],
                            $data['note'] ?? null,
                        );
                    } catch (BusinessRuleViolation|ApprovalDenied $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->success()
                        ->title(match (true) {
                            bccomp((string) $count->difference_kg, '0', 3) === 0 => 'Count matches the books',
                            $count->fresh()?->status === 'applied' => "Difference of {$count->difference_kg} kg booked (no second approval needed)",
                            default => "Difference of {$count->difference_kg} kg sent for approval",
                        })
                        ->send();
                }),
        ];
    }
}
