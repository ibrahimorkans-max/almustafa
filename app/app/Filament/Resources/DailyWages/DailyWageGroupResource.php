<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyWages;

use App\Filament\Resources\DailyWages\DailyWageGroupResource\Pages;
use App\Models\DailyWages\DailyWageCategory;
use App\Models\DailyWages\DailyWageGroup;
use App\Models\DailyWages\DailyWageSheet;
use App\Support\Values\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Labour groups: workers who come and work together under one person (the mukadam / jamadar). Only
 * the leader is noted; the workers need no registration.
 */
class DailyWageGroupResource extends Resource
{
    protected static ?string $model = DailyWageGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Daily wages';

    protected static ?string $navigationLabel = 'Labour groups';

    protected static ?string $modelLabel = 'labour group';

    protected static ?string $slug = 'labour-groups';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            TextInput::make('name')->label('Group name')->required()->maxLength(120)->placeholder('e.g. Akram loading group'),
            TextInput::make('leader_name')->label('Leader (mukadam)')->required()->maxLength(120),
            TextInput::make('leader_phone')->label('Leader\'s phone')->tel()->maxLength(20),
            TextInput::make('leader_cnic')->label('Leader\'s CNIC (optional)')->maxLength(15)->regex('/^\d{5}-?\d{7}-?\d$/'),
            Select::make('default_category_id')->label('Usual work')->searchable()
                ->options(fn () => DailyWageCategory::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
            TextInput::make('usual_size')->label('Usual number of workers')->numeric()->minValue(1)->maxValue(500),
            Textarea::make('notes')->rows(2)->maxLength(1000)->columnSpanFull(),
            Select::make('status')->options(['active' => 'Working with us', 'inactive' => 'Not any more'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('defaultCategory'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('leader_name')->label('Leader')->searchable()->description(fn (DailyWageGroup $g) => $g->leader_phone),
                TextColumn::make('defaultCategory.name')->label('Usual work')->placeholder('—'),
                TextColumn::make('usual_size')->label('Workers')->placeholder('—'),
                TextColumn::make('this_month')->label('Paid this month')->alignEnd()
                    ->state(fn (DailyWageGroup $g) => Money::paise((int) DailyWageSheet::query()->where('group_id', $g->id)
                        ->whereIn('status', [DailyWageSheet::POSTED, DailyWageSheet::PAID])
                        ->where('work_date', '>=', now('Asia/Karachi')->startOfMonth()->toDateString())->sum('net_paise'))->format()),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->filters([SelectFilter::make('status')->options(['active' => 'Working with us', 'inactive' => 'Not any more'])->default('active')])
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDailyWageGroups::route('/')];
    }
}
