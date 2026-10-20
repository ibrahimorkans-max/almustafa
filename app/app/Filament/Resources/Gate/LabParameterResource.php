<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate;

use App\Filament\Resources\Gate\LabParameterResource\Pages;
use App\Filament\Support\Units;
use App\Models\Gate\LabParameter;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The lab tests a lot is checked against, with English and Urdu names, a standard limit and a grace
 * limit (App\Domain\Gate\LabStandards). Above the grace limit a lot is rejected automatically. A test
 * can be set for one product; it then replaces the general one for that product.
 */
class LabParameterResource extends Resource
{
    protected static ?string $model = LabParameter::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Lab tests & limits';

    protected static ?string $modelLabel = 'lab test';

    protected static ?string $slug = 'lab-tests';

    protected static ?int $navigationSort = 39;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            TextInput::make('code')->required()->maxLength(30)->regex('/^[a-z][a-z0-9_]*$/')
                ->helperText('Short code in small letters, e.g. moisture. Not changed once results use it.')
                ->disabledOn('edit'),
            Select::make('product_id')->label('Product (empty: every product)')->preload()->searchable()
                ->relationship('product', 'name', fn (Builder $query) => $query->where('category', 'paddy')),
            TextInput::make('name_en')->label('Name (English)')->required()->maxLength(80),
            TextInput::make('name_ur')->label('Name (Urdu)')->required()->maxLength(80)->extraInputAttributes(['dir' => 'rtl']),
            TextInput::make('standard')->label('Standard limit (%)')->required()->regex('/^\d{1,3}(\.\d{1,2})?$/')
                ->helperText('Up to this the result is within standard.')
                ->afterStateHydrated(fn (TextInput $component, ?LabParameter $record) => $component->state($record ? rtrim(rtrim(number_format($record->standard_bp / 100, 2, '.', ''), '0'), '.') : null)),
            TextInput::make('grace')->label('Grace limit (%)')->required()->regex('/^\d{1,3}(\.\d{1,2})?$/')
                ->helperText('Above the standard and up to this: grace warning. Above this the lot is rejected automatically.')
                ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get): void {
                    if (is_numeric($value) && is_numeric($get('standard')) && (float) $value < (float) $get('standard')) {
                        $fail('The grace limit cannot be below the standard limit.');
                    }
                })
                ->afterStateHydrated(fn (TextInput $component, ?LabParameter $record) => $component->state($record ? rtrim(rtrim(number_format($record->grace_bp / 100, 2, '.', ''), '0'), '.') : null)),
            TextInput::make('unit')->default('%')->required()->maxLength(10),
            TextInput::make('sort')->label('Order on the lab form')->integer()->minValue(0)->default(100),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Not used'])->default('active')->required(),
        ]);
    }

    /**
     * The two limits typed in percent are stored in basis points.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function limits(array $data): array
    {
        $data['standard_bp'] = (int) Units::percentToBp((string) ($data['standard'] ?? '0'));
        $data['grace_bp'] = (int) Units::percentToBp((string) ($data['grace'] ?? '0'));
        unset($data['standard'], $data['grace']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        $pct = fn (int $state) => Units::bpToPercent($state);

        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name_en')->label('Test')->description(fn (LabParameter $r) => $r->name_ur)->searchable(),
                TextColumn::make('code')->fontFamily('mono'),
                TextColumn::make('product.name')->label('Product')->placeholder('Every product'),
                TextColumn::make('standard_bp')->label('Standard up to')->formatStateUsing($pct),
                TextColumn::make('grace_bp')->label('Grace up to')->formatStateUsing($pct)->description('Above: rejected automatically'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->actions([EditAction::make()->mutateFormDataUsing(fn (array $data) => self::limits($data))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLabParameters::route('/')];
    }
}
