<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Domain\Accounts\BankReconciliation;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\BankStatementLineResource\Pages;
use App\Filament\Support\Units;
use App\Models\Accounts\BankStatementLine;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\VoucherLine;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Bank reconciliation: imported statement lines and the book entries they confirm. */
class BankStatementLineResource extends Resource
{
    protected static ?string $model = BankStatementLine::class;

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Bank reconciliation';

    protected static ?string $modelLabel = 'statement line';

    protected static ?string $slug = 'bank-reconciliation';

    protected static ?int $navigationSort = 70;

    public static function table(Table $table): Table
    {
        $recon = fn () => app(BankReconciliation::class);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('voucherLine.voucher'))
            ->columns([
                TextColumn::make('txn_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('description')->wrap()->searchable(),
                TextColumn::make('reference')->placeholder('—'),
                TextColumn::make('debit_paise')->label('Withdrawal')->alignEnd()->formatStateUsing(fn (int $state) => $state ? Units::paiseToRupees($state) : ''),
                TextColumn::make('credit_paise')->label('Deposit')->alignEnd()->formatStateUsing(fn (int $state) => $state ? Units::paiseToRupees($state) : ''),
                TextColumn::make('voucherLine.voucher.number')->label('Matched to')->placeholder('unmatched')
                    ->badge()->color(fn ($state) => $state ? 'success' : 'danger'),
                TextColumn::make('match_method')->label('How')->placeholder(''),
            ])
            ->defaultSort('txn_date', 'desc')
            ->filters([
                SelectFilter::make('ledger_account_id')->label('Bank account')
                    ->options(fn () => LedgerAccount::query()->where('kind', 'bank')->get()->mapWithKeys(fn (LedgerAccount $l) => [$l->id => $l->displayName()])),
                TernaryFilter::make('matched')->label('Matched')->default(false)
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('voucher_line_id'),
                        false: fn (Builder $q) => $q->whereNull('voucher_line_id'),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->actions([
                Action::make('match')->icon('heroicon-o-link')
                    ->visible(fn (BankStatementLine $r) => $r->voucher_line_id === null)
                    ->form(fn (BankStatementLine $record) => [
                        Select::make('voucher_line_id')->label('Book entry (same account, side and amount, ±5 days)')->required()
                            ->options($recon()->candidates($record)
                                ->mapWithKeys(fn (VoucherLine $l) => [$l->id => "{$l->voucher->number} · {$l->voucher_date->format('d M')} · ".mb_substr((string) $l->voucher->narration, 0, 60)])),
                    ])
                    ->action(function (BankStatementLine $record, array $data) use ($recon): void {
                        try {
                            $recon()->match($record, VoucherLine::query()->findOrFail($data['voucher_line_id']));
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('unmatch')->color('gray')->requiresConfirmation()
                    ->visible(fn (BankStatementLine $r) => $r->voucher_line_id !== null)
                    ->action(fn (BankStatementLine $record) => $recon()->unmatch($record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBankStatementLines::route('/')];
    }
}
