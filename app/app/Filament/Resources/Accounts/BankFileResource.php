<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Domain\Accounts\BankFiles;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\BankFileResource\Pages;
use App\Filament\Support\Units;
use App\Models\Accounts\BankFile;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Bulk-transfer files for the bank portal, one per batch of released bank payments. */
class BankFileResource extends Resource
{
    protected static ?string $model = BankFile::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Bank files';

    protected static ?string $slug = 'bank-files';

    protected static ?int $navigationSort = 60;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('bank'))
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('created_at')->label('Created')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('bank.name')->label('From account')->state(fn (BankFile $r) => $r->bank->displayName()),
                TextColumn::make('payment_count')->label('Payments')->alignEnd(),
                TextColumn::make('total_paise')->label('Total')->alignEnd()->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextColumn::make('sha256')->label('SHA-256')->limit(12)->fontFamily('mono'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('download')->label('Download CSV')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => Filament::auth()->user()?->can('bank.view') ?? false)
                    ->action(function (BankFile $record) {
                        try {
                            $csv = app(BankFiles::class)->csv($record);
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return null;
                        }

                        return response()->streamDownload(fn () => print $csv, str_replace('/', '-', $record->number).'.csv', ['Content-Type' => 'text/csv']);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBankFiles::route('/')];
    }
}
