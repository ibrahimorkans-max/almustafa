<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters;

use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Masters\Concerns\MasterResourceHelpers;
use App\Filament\Resources\Masters\PaddyVarietyResource\Pages;
use App\Models\Masters\PaddyVariety;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/** Paddy varieties: Masters → Paddy varieties, and "add a variety" on the sauda and stock entry forms. */
class PaddyVarietyResource extends Resource
{
    use MasterResourceHelpers;

    protected static ?string $model = PaddyVariety::class;

    protected static ?string $recordTitleAttribute = 'name';

    /** Found by the search box in the top bar (partial match, this mill only, and only for people who may open the list). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code'];
    }

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Paddy varieties';

    protected static ?string $modelLabel = 'paddy variety';

    protected static ?string $slug = 'paddy-varieties';

    protected static ?int $navigationSort = 41;

    /** @return list<Component> the fields, also used for "add a variety" elsewhere */
    public static function fields(): array
    {
        return [
            TextInput::make('code')->label('Code')->disabled()->dehydrated(false)->placeholder('Made automatically (PV-…)')
                ->helperText('Permanent: it stays when the variety is renamed.'),
            TextInput::make('name')->label('Variety name')->required()->maxLength(120)->placeholder('Super Basmati, 1121, IRRI-6 …'),
            Select::make('category')->label('Paddy category / type')->options(PaddyVariety::CATEGORIES)->default('basmati')->required(),
            Select::make('unit')->label('Unit of measurement')->options(PaddyVariety::UNITS)->default('kg')->required()
                ->helperText('Stock is always kept in kilograms; this is the unit people deal in.'),
            Textarea::make('description')->maxLength(1000)->columnSpanFull(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema(self::fields());
    }

    /**
     * Saves a variety from a form, turning a duplicate name into a form error.
     *
     * @param  array<string, mixed>  $data
     */
    public static function store(array $data, ?PaddyVariety $record = null, string $field = 'name'): PaddyVariety
    {
        $record ??= new PaddyVariety;
        try {
            $record->fill($data)->save();
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }

        return $record;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('category')->formatStateUsing(fn (string $state) => PaddyVariety::CATEGORIES[$state] ?? $state),
                TextColumn::make('unit'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                static::updatedColumn(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive']),
                SelectFilter::make('category')->options(PaddyVariety::CATEGORIES),
            ])
            ->actions([EditAction::make()->using(fn (PaddyVariety $record, array $data) => self::store($data, $record, 'mountedTableActionsData.0.name'))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePaddyVarieties::route('/')];
    }
}
