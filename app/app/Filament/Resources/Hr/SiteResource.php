<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Filament\Resources\Hr\SiteResource\Pages;
use App\Models\Hr\Site;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Work sites with their geofence (centre + radius) and, optionally, the hours punches are expected. */
class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Attendance sites';

    protected static ?int $navigationSort = 50;

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            TextInput::make('code')->required()->maxLength(20)->regex('/^[A-Za-z0-9._-]+$/')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => HrScope::inEntity($rule)),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('kind')->options(Site::KINDS)->required(),
            TextInput::make('latitude')->required()->numeric()->minValue(-90)->maxValue(90)->helperText('e.g. 29.7891234 (from Google Maps: long-press the spot)'),
            TextInput::make('longitude')->required()->numeric()->minValue(-180)->maxValue(180),
            TextInput::make('radius_m')->label('Radius (m)')->required()->integer()->minValue(20)->maxValue(5000)->default(150),
            Repeater::make('time_windows')->label('Working hours (PKT) — leave empty for any time')->columnSpanFull()->columns(3)
                ->defaultItems(0)
                ->schema([
                    CheckboxList::make('days')->options(self::DAYS)->columns(7)->required()->columnSpanFull(),
                    TimePicker::make('from')->seconds(false)->required(),
                    TimePicker::make('to')->seconds(false)->required()->helperText('Earlier than "from" = crosses midnight'),
                ]),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('kind')->formatStateUsing(fn (string $state) => Site::KINDS[$state] ?? $state),
                TextColumn::make('radius_m')->label('Radius')->suffix(' m'),
                TextColumn::make('hours')->state(fn (Site $r) => $r->time_windows ? collect($r->time_windows)
                    ->map(fn (array $w) => implode(',', array_map(fn ($d) => self::DAYS[(int) $d] ?? $d, $w['days'] ?? [])).' '.substr((string) $w['from'], 0, 5).'–'.substr((string) $w['to'], 0, 5))
                    ->implode('; ') : 'any time'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('code')
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSites::route('/')];
    }
}
