<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pricing\PriceListResource\Pages;

use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Pricing\PricingService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Pricing\PriceListResource;
use App\Filament\Support\ApprovalNotice;
use App\Filament\Support\ProductSelect;
use App\Filament\Support\Units;
use App\Models\LoginAccount;
use App\Models\Masters\Product;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

/**
 * Current rates and their history. Cost and margin appear only for holders of
 * `pricing.margin.view`; for everyone else those entries are not rendered at all.
 */
class ViewPriceList extends ViewRecord
{
    protected static string $resource = PriceListResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        /** @var LoginAccount|null $me */
        $me = Filament::auth()->user();
        $seesMargin = $me?->hasPermission('pricing.margin.view') ?? false;
        $pricing = app(PricingService::class);

        return $infolist->schema([
            Section::make()->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('valid_from')->date('d M Y'),
                TextEntry::make('valid_to')->date('d M Y')->placeholder('open-ended'),
            ]),
            Section::make('Current rates')->schema([
                RepeatableEntry::make('current')->hiddenLabel()->columns($seesMargin ? 5 : 2)
                    ->state(function (PriceList $record) use ($pricing, $me): array {
                        return PriceListItem::query()->where('price_list_id', $record->id)->with('product')->get()
                            ->groupBy('product_id')
                            ->map(function ($items) use ($pricing, $me) {
                                $item = $items->sortByDesc(fn (PriceListItem $i) => $i->created_at->format('Y-m-d H:i:s.u').$i->id)->first();
                                $margin = $pricing->margin($me, $item->product, $item->rate_paise_per_maund);

                                return ['product' => $item->product->name, 'rate' => Units::paiseToRupees($item->rate_paise_per_maund)] + ($margin === null ? [] : [
                                    'cost' => Units::paiseToRupees($margin['cost_paise_per_maund']),
                                    'margin' => Units::paiseToRupees($margin['margin_paise_per_maund']).($margin['margin_bp'] !== null ? ' ('.Units::bpToPercent($margin['margin_bp']).')' : ''),
                                    'suggested' => Units::paiseToRupees($margin['suggested_paise_per_maund']),
                                ]);
                            })->values()->all();
                    })
                    ->schema([
                        TextEntry::make('product'),
                        TextEntry::make('rate')->label('Rate / maund'),
                        ...($seesMargin ? [
                            TextEntry::make('cost')->label('Cost / maund'),
                            TextEntry::make('margin')->label('Margin / maund'),
                            TextEntry::make('suggested')->label('Suggested (markup)'),
                        ] : []),
                    ]),
            ]),
            Section::make('History')->collapsible()->collapsed()->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->label('From')->dateTime('d M Y H:i'),
                    TextEntry::make('product.name')->label('Product'),
                    TextEntry::make('rate_paise_per_maund')->label('Rate / maund')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                    TextEntry::make('reason')->placeholder('—'),
                ]),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rate')->label('Request rate change')->icon('heroicon-o-pencil')
                ->visible(fn () => Filament::auth()->user()?->can('pricing.rate.edit') ?? false)
                ->modalDescription('Applied after a different person approves it. The old rate stays in the history.')
                ->form([
                    ProductSelect::make('product_id', ['rice', 'by_product'])->required(),
                    TextInput::make('rate')->label('New rate (Rs per maund)')->required()->regex(Units::DECIMAL_2),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(1000),
                ])
                ->action(function (PriceList $record, array $data): void {
                    try {
                        $request = app(PricingService::class)->requestRate($record, Product::query()->findOrFail($data['product_id']), Units::rupeesToPaise($data['rate']), $data['reason']);
                    } catch (BusinessRuleViolation|ApprovalDenied|ValidationException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    ApprovalNotice::send($request, 'Rate change sent for approval', link: false);
                }),
        ];
    }
}
