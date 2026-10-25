<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\LabParameterResource\Pages;

use App\Filament\Resources\Gate\LabParameterResource;
use App\Models\Gate\LabParameter;
use App\Models\Masters\Product;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageLabParameters extends ManageRecords
{
    protected static string $resource = LabParameterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add lab test')->mutateFormDataUsing(fn (array $data) => LabParameterResource::limits($data)),
            // Each variety its own limits: start from the general tests, then edit that product's limits.
            Action::make('copyToProduct')->label('Copy tests to a product / variety')->icon('heroicon-o-document-duplicate')->color('gray')
                ->visible(fn () => Filament::auth()->user()?->can('create', LabParameter::class) ?? false)
                ->modalDescription('Adds the general lab tests to this product with the same limits; then edit its limits. Tests it already has are kept.')
                ->form([
                    Select::make('product_id')->label('Product / variety')->required()->searchable()
                        ->options(fn () => Product::query()->where('category', 'paddy')->orderBy('name')->get()
                            ->mapWithKeys(fn (Product $p) => [$p->id => $p->name.($p->variety ? ' ('.$p->variety.')' : '')])->all()),
                ])
                ->action(function (array $data): void {
                    $have = LabParameter::query()->where('product_id', $data['product_id'])->pluck('code')->all();
                    $added = 0;
                    foreach (LabParameter::query()->whereNull('product_id')->where('status', 'active')->orderBy('sort')->get() as $general) {
                        if (in_array($general->code, $have, true)) {
                            continue;
                        }
                        LabParameter::query()->create(['product_id' => $data['product_id']]
                            + $general->only(['code', 'name_en', 'name_ur', 'unit', 'standard_bp', 'grace_bp', 'sort', 'status']));
                        $added++;
                    }
                    Notification::make()->success()->title($added === 0 ? 'This product already has every test.' : "{$added} tests added to this product: now edit their limits.")->send();
                    $this->tableFilters['product_id']['value'] = $data['product_id'];
                }),
        ];
    }
}
