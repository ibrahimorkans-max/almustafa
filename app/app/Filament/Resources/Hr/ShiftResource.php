<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Filament\Resources\Hr\ShiftResource\Pages;
use App\Models\Hr\Shift;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?int $navigationSort = 70;

    public static function form(Form $form): Form
    {
        $hhmm = '/^([01]\d|2[0-3]):[0-5]\d$/';

        return $form->columns(3)->schema([
            TextInput::make('code')->required()->maxLength(20)->regex('/^[A-Za-z0-9._-]+$/')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => HrScope::inEntity($rule)),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            TextInput::make('start_time')->label('Start (HH:MM, PKT)')->required()->regex($hhmm)->default('09:00'),
            TextInput::make('end_time')->label('End (HH:MM)')->required()->regex($hhmm)->default('18:00')
                ->helperText('Earlier than the start = night shift ending the next morning'),
            TextInput::make('break_minutes')->label('Break (min)')->required()->integer()->minValue(0)->maxValue(240)->default(60),
            TextInput::make('full_day_minutes')->label('Full day from (min worked)')->required()->integer()->minValue(60)->default(420),
            TextInput::make('half_day_minutes')->label('Half day from (min worked)')->required()->integer()->minValue(30)->default(210),
            TextInput::make('overtime_after_minutes')->label('Overtime after (min beyond the shift)')->required()->integer()->minValue(0)->default(30),
            TextInput::make('grace_late_minutes')->label('Late after (min grace)')->required()->integer()->minValue(0)->default(10),
            TextInput::make('grace_early_minutes')->label('Early leaving after (min grace)')->required()->integer()->minValue(0)->default(10),
            CheckboxList::make('weekly_offs')->label('Weekly off')->options(SiteResource::DAYS)->columns(7)->default([7])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name'),
                TextColumn::make('hours')->state(fn (Shift $r) => "{$r->start_time}–{$r->end_time}".($r->crossesMidnight() ? ' (next day)' : '')),
                TextColumn::make('weekly_offs')->label('Weekly off')
                    ->formatStateUsing(fn ($state, Shift $r) => implode(', ', array_map(fn ($d) => SiteResource::DAYS[(int) $d] ?? $d, $r->weekly_offs ?? [])) ?: '—'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('code')
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageShifts::route('/')];
    }
}
