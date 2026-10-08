<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Domain\Accounts\Books;
use App\Filament\Pages\AccountsReports;
use App\Filament\Resources\Accounts\LedgerAccountResource\Pages;
use App\Filament\Support\Units;
use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\LedgerAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Chart of accounts. Party and system ledgers are created by the application. */
class LedgerAccountResource extends Resource
{
    protected static ?string $model = LedgerAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Ledgers';

    protected static ?string $modelLabel = 'ledger';

    protected static ?string $slug = 'ledgers';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('kind')->options(['general' => 'General', 'bank' => 'Mill bank account'])->default('general')->required()->live()
                ->disabledOn('edit'),
            Select::make('group_id')->label('Group')->required()
                ->options(fn () => AccountGroup::query()->orderBy('sort')->pluck('name', 'id')),
            TextInput::make('code')->required()->maxLength(40)->regex('/^[A-Za-z0-9._-]+$/')->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(160),
            TextInput::make('bank_account_number')->label('Account number')->regex('/^\d{6,18}$/')
                ->visible(fn (Get $get) => $get('kind') === 'bank')->required(fn (Get $get) => $get('kind') === 'bank')
                ->visibleOn('create'),
            TextInput::make('bank_ifsc')->label('IFSC')->regex('/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/')
                ->visible(fn (Get $get) => $get('kind') === 'bank')->required(fn (Get $get) => $get('kind') === 'bank'),
            TextInput::make('bank_name')->maxLength(120)->visible(fn (Get $get) => $get('kind') === 'bank'),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('group'))
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable()->state(fn (LedgerAccount $r) => $r->displayName()),
                TextColumn::make('group.name')->label('Group'),
                TextColumn::make('kind')->badge(),
                TextColumn::make('balance')->label('Balance')->alignEnd()
                    ->state(function (LedgerAccount $r) {
                        $b = app(Books::class)->balance($r);

                        return $b === 0 ? '—' : Units::paiseToRupees(abs($b)).($b > 0 ? ' Dr' : ' Cr');
                    }),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('kind')->options(array_combine(LedgerAccount::KINDS, array_map('ucfirst', LedgerAccount::KINDS))),
                SelectFilter::make('group_id')->label('Group')->options(fn () => AccountGroup::query()->orderBy('sort')->pluck('name', 'id')),
            ])
            ->actions([
                Action::make('statement')->label('Statement')->icon('heroicon-o-document-text')->color('gray')
                    ->url(fn (LedgerAccount $r) => AccountsReports::getUrl(['report' => 'ledger', 'ledger' => $r->id])),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLedgerAccounts::route('/')];
    }
}
