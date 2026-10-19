<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production;

use App\Domain\Inventory\StockLedger;
use App\Domain\Production\ProductionService;
use App\Filament\Resources\Inventory\RequisitionResource;
use App\Filament\Resources\Production\ProductionBatchResource\Pages;
use App\Filament\Support\HidesArchived;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Production\ProductionBatch;
use App\Models\Production\ProductionLog;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductionBatchResource extends Resource
{
    use HidesArchived;

    protected static ?string $model = ProductionBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Milling batches';

    protected static ?string $modelLabel = 'batch';

    protected static ?string $slug = 'batches';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Placeholder::make('number')->label('Batch number')->content('Given automatically when the batch is created'),
            Select::make('paddy_product_id')->label('Paddy (variety)')->required()->preload()->live()
                ->relationship('paddy', 'name', fn (Builder $query) => $query->where('category', 'paddy')->where('status', 'active')),
            Toggle::make('via_requisition')->label('Paddy will come through a stores requisition')->live()->columnSpanFull()
                ->helperText('Off: the paddy below is issued from the godown now. On: the batch opens empty and the stores issue paddy against an approved requisition.'),
            Select::make('godown_id')->label('Issue paddy from')->live()
                ->required(fn (Get $get) => ! $get('via_requisition'))->hidden(fn (Get $get) => (bool) $get('via_requisition'))
                ->options(fn () => RequisitionResource::godownOptions())
                ->helperText(fn (Get $get) => static::availableText($get('paddy_product_id'), $get('godown_id'))),
            TextInput::make('issue_kg')->label('Paddy issued to production now (kg)')->regex(Units::DECIMAL_3)
                ->required(fn (Get $get) => ! $get('via_requisition'))->hidden(fn (Get $get) => (bool) $get('via_requisition'))
                ->helperText('Posted to the stock ledger as an issue to production. More paddy can be issued later from the batch.'),
            TextInput::make('planned_kg')->label('Planned paddy for the batch (kg)')->regex(Units::DECIMAL_3),
            TextInput::make('line')->label('Mill line / unit')->maxLength(60),
            Select::make('supervisor_login_account_id')->label('Supervisor')->searchable()
                ->options(fn () => LoginAccount::query()->with(['person', 'role'])->where('status', LoginAccount::STATUS_ACTIVE)->get()
                    ->filter(fn (LoginAccount $a) => $a->hasPermission('production.log') || $a->hasPermission('batch.manage'))
                    ->mapWithKeys(fn (LoginAccount $a) => [$a->id => ($a->person->full_name ?? $a->username).' ('.$a->role->name.')'])),
            DateTimePicker::make('opened_at')->label('Start time')->default(now())->seconds(false)->required()->maxDate(now()->addMinutes(5)),
            Textarea::make('notes')->maxLength(2000)->columnSpanFull(),
        ]);
    }

    /** Honest stock figure for the chosen paddy and godown, so the issue is not a guess. */
    public static function availableText(?string $productId, ?string $godownId): ?string
    {
        if (! $productId || ! $godownId) {
            return null;
        }
        $product = Product::query()->find($productId);
        $godown = Godown::query()->find($godownId);
        if ($product === null || $godown === null) {
            return null;
        }

        return 'In stock here: '.Units::kgToMaund(app(StockLedger::class)->balance($product, $godown)->kg).' maund';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('paddy'))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('paddy.name')->label('Paddy'),
                TextColumn::make('line')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'open' ? 'warning' : 'success'),
                TextColumn::make('input_kg')->label('Paddy in (maund)')->alignEnd()->formatStateUsing(fn (string $state) => Units::kgToMaund($state)),
                TextColumn::make('output_kg')->label('Output (maund)')->alignEnd()->formatStateUsing(fn (string $state) => Units::kgToMaund($state)),
                TextColumn::make('unaccounted')->label('Unaccounted loss')
                    ->state(fn (ProductionBatch $record) => $record->yield_snapshot
                        ? Units::bpToPercent((int) $record->yield_snapshot['unaccounted_bp'])
                        : '—'),
                TextColumn::make('opened_at')->label('Started')->dateTime('d M H:i')->sortable(),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'closed' => 'Closed']),
            ])
            ->actions([ViewAction::make(), PrintButton::row('batch')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->color(fn (string $state) => $state === 'open' ? 'warning' : 'success'),
                TextEntry::make('paddy.name')->label('Paddy'),
                TextEntry::make('line')->placeholder('—'),
                TextEntry::make('planned_kg')->label('Planned paddy (kg)')->fontFamily('mono')->placeholder('—'),
                TextEntry::make('supervisor.username')->label('Supervisor')->placeholder('—'),
                TextEntry::make('opened_at')->label('Started')->dateTime('d M Y H:i'),
                TextEntry::make('closed_at')->label('Closed')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('input_kg')->label('Paddy in (kg)')->fontFamily('mono'),
                TextEntry::make('output_kg')->label('Output (kg)')->fontFamily('mono'),
            ]),
            Section::make(fn (ProductionBatch $record) => $record->isOpen() ? 'Yield so far' : 'Yield (frozen at close)')->schema([
                TextEntry::make('unaccounted')->label('Unaccounted (input − all outputs)')
                    ->state(fn (ProductionBatch $record) => self::yieldOf($record)['unaccounted_kg'].' kg ('.Units::bpToPercent((int) self::yieldOf($record)['unaccounted_bp']).')'),
                RepeatableEntry::make('outturn')->hiddenLabel()->columns(5)
                    ->state(fn (ProductionBatch $record) => array_values(self::yieldOf($record)['outturn'] ?? []))
                    ->schema([
                        TextEntry::make('product')->label('Output'),
                        TextEntry::make('kg')->label('kg')->fontFamily('mono'),
                        TextEntry::make('bp')->label('Outturn')->formatStateUsing(fn ($state) => Units::bpToPercent((int) $state)),
                        TextEntry::make('expected_bp')->label('Standard')->formatStateUsing(fn ($state) => $state === null ? '—' : Units::bpToPercent((int) $state))->placeholder('—'),
                        TextEntry::make('out_of_range')->label('')->badge()
                            ->formatStateUsing(fn ($state) => $state ? 'Outside tolerance' : 'OK')
                            ->color(fn ($state) => $state ? 'danger' : 'success'),
                    ]),
            ]),
            Section::make('Production logs')->schema([
                RepeatableEntry::make('logs')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('period_start')->label('Period')->dateTime('d M H:i')
                        ->suffix(fn (ProductionLog $record) => ' – '.$record->period_end->format('H:i')),
                    TextEntry::make('kind')->badge()->color(fn (string $state) => $state === 'input' ? 'gray' : 'success'),
                    TextEntry::make('product.name')->label('Product'),
                    TextEntry::make('godown.name')->label('Godown'),
                    TextEntry::make('quantity_kg')->label('kg')->fontFamily('mono')
                        ->prefix(fn (ProductionLog $record) => $record->isReversal() ? '− ' : '')
                        ->color(fn (ProductionLog $record) => $record->isReversal() ? 'danger' : null),
                    TextEntry::make('note')->placeholder('—')
                        ->formatStateUsing(fn (?string $state, ProductionLog $record) => $record->isReversal() ? "Correction: {$state}" : $state),
                ]),
            ])->collapsible(),
        ]);
    }

    /** @return array<string, mixed> */
    public static function yieldOf(ProductionBatch $batch): array
    {
        return app(ProductionService::class)->yieldOf($batch);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductionBatches::route('/'),
            'create' => Pages\CreateProductionBatch::route('/open'),
            'view' => Pages\ViewProductionBatch::route('/{record}'),
        ];
    }
}
