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
    use \App\Filament\Concerns\TranslatesResourceLabels;

    use HidesArchived;

    protected static ?string $model = ProductionBatch::class;

    protected static ?string $recordTitleAttribute = 'number';

    /** Found by the search box in the top bar (partial match, this mill only, and only for people who may open the list). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Milling batches';

    protected static ?string $modelLabel = 'batch';

    protected static ?string $slug = 'batches';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Placeholder::make('number')->label(__('Batch number'))->content(__('Given automatically when the batch is created')),
            Select::make('paddy_product_id')->label(__('Paddy (variety)'))->required()->preload()->live()
                ->relationship('paddy', 'name', fn (Builder $query) => $query->where('category', 'paddy')->where('status', 'active')),
            Toggle::make('via_requisition')->label(__('Paddy will come through a stores requisition'))->live()->columnSpanFull()
                ->helperText(__('Off: the paddy below is issued from the godown now. On: the batch opens empty and the stores issue paddy against an approved requisition.')),
            Select::make('godown_id')->label(__('Issue paddy from'))->live()
                ->required(fn (Get $get) => ! $get('via_requisition'))->hidden(fn (Get $get) => (bool) $get('via_requisition'))
                ->options(fn () => RequisitionResource::godownOptions())
                ->helperText(fn (Get $get) => static::availableText($get('paddy_product_id'), $get('godown_id'))),
            TextInput::make('issue_kg')->label(__('Paddy issued to production now (kg)'))->regex(Units::DECIMAL_3)
                ->required(fn (Get $get) => ! $get('via_requisition'))->hidden(fn (Get $get) => (bool) $get('via_requisition'))
                ->helperText(__('Posted to the stock ledger as an issue to production. More paddy can be issued later from the batch.')),
            TextInput::make('planned_kg')->label(__('Planned paddy for the batch (kg)'))->regex(Units::DECIMAL_3),
            TextInput::make('line')->label(__('Mill line / unit'))->maxLength(60),
            Select::make('supervisor_login_account_id')->label(__('Supervisor'))->searchable()
                ->options(fn () => LoginAccount::query()->with(['person', 'role'])->where('status', LoginAccount::STATUS_ACTIVE)->get()
                    ->filter(fn (LoginAccount $a) => $a->hasPermission('production.log') || $a->hasPermission('batch.manage'))
                    ->mapWithKeys(fn (LoginAccount $a) => [$a->id => ($a->person->full_name ?? $a->username).' ('.$a->role->name.')'])),
            DateTimePicker::make('opened_at')->label(__('Start time'))->default(now())->seconds(false)->required()->maxDate(now()->addMinutes(5)),
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
                TextColumn::make('paddy.name')->label(__('Paddy')),
                TextColumn::make('line')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'open' ? 'warning' : 'success'),
                TextColumn::make('input_kg')->label(__('Paddy in (maund)'))->alignEnd()->formatStateUsing(fn (string $state) => Units::kgToMaund($state)),
                TextColumn::make('output_kg')->label(__('Output (maund)'))->alignEnd()->formatStateUsing(fn (string $state) => Units::kgToMaund($state)),
                TextColumn::make('unaccounted')->label(__('Unaccounted loss'))
                    ->state(fn (ProductionBatch $record) => $record->yield_snapshot
                        ? Units::bpToPercent((int) $record->yield_snapshot['unaccounted_bp'])
                        : '—'),
                TextColumn::make('opened_at')->label(__('Started'))->dateTime('d M H:i')->sortable(),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['open' => __('Open'), 'closed' => __('Closed')]),
            ])
            ->actions([ViewAction::make(), PrintButton::row('batch')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->color(fn (string $state) => $state === 'open' ? 'warning' : 'success'),
                TextEntry::make('paddy.name')->label(__('Paddy')),
                TextEntry::make('line')->placeholder('—'),
                TextEntry::make('planned_kg')->label(__('Planned paddy (kg)'))->fontFamily('mono')->placeholder('—'),
                TextEntry::make('supervisor.username')->label(__('Supervisor'))->placeholder('—'),
                TextEntry::make('opened_at')->label(__('Started'))->dateTime('d M Y H:i'),
                TextEntry::make('closed_at')->label(__('Closed'))->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('input_kg')->label(__('Paddy in (kg)'))->fontFamily('mono'),
                TextEntry::make('output_kg')->label(__('Output (kg)'))->fontFamily('mono'),
            ]),
            Section::make(__('Input and outputs'))->description(__('Paddy fed = all outputs + processing loss. Recovery = output ÷ paddy fed × 100.'))->columns(4)->schema([
                TextEntry::make('rec_input')->label(__('Paddy fed (kg)'))->fontFamily('mono')->state(fn (ProductionBatch $record) => app(ProductionService::class)->reconciliation($record)['input_kg']),
                TextEntry::make('rec_output')->label(__('All outputs (kg)'))->fontFamily('mono')->state(fn (ProductionBatch $record) => app(ProductionService::class)->reconciliation($record)['output_kg']),
                TextEntry::make('rec_loss')->label(__('Processing loss (kg)'))->fontFamily('mono')->weight('bold')
                    ->state(fn (ProductionBatch $record) => app(ProductionService::class)->reconciliation($record)['loss_kg']),
                TextEntry::make('rec_loss_pct')->label(__('Processing loss'))->state(fn (ProductionBatch $record) => app(ProductionService::class)->reconciliation($record)['loss_percent']),
            ]),
            Section::make(fn (ProductionBatch $record) => $record->isOpen() ? 'Yield so far' : 'Yield (frozen at close)')->schema([
                TextEntry::make('unaccounted')->label(__('Unaccounted (input − all outputs)'))
                    ->state(fn (ProductionBatch $record) => self::yieldOf($record)['unaccounted_kg'].' kg ('.Units::bpToPercent((int) self::yieldOf($record)['unaccounted_bp']).')'),
                RepeatableEntry::make('outturn')->hiddenLabel()->columns(5)
                    ->state(fn (ProductionBatch $record) => array_values(self::yieldOf($record)['outturn'] ?? []))
                    ->schema([
                        TextEntry::make('product')->label(__('Output')),
                        TextEntry::make('kg')->label('kg')->fontFamily('mono'),
                        TextEntry::make('bp')->label(__('Outturn'))->formatStateUsing(fn ($state) => Units::bpToPercent((int) $state)),
                        TextEntry::make('expected_bp')->label(__('Standard'))->formatStateUsing(fn ($state) => $state === null ? '—' : Units::bpToPercent((int) $state))->placeholder('—'),
                        TextEntry::make('out_of_range')->label('')->badge()
                            ->formatStateUsing(fn ($state) => $state ? 'Outside tolerance' : 'OK')
                            ->color(fn ($state) => $state ? 'danger' : 'success'),
                    ]),
            ]),
            Section::make(__('Production logs'))->schema([
                RepeatableEntry::make('logs')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('period_start')->label(__('Period'))->dateTime('d M H:i')
                        ->suffix(fn (ProductionLog $record) => ' – '.$record->period_end->format('H:i')),
                    TextEntry::make('kind')->badge()->color(fn (string $state) => $state === 'input' ? 'gray' : 'success'),
                    TextEntry::make('product.name')->label(__('Product')),
                    TextEntry::make('godown.name')->label(__('Godown')),
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
