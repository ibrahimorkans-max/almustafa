<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banking;

use App\Domain\Banking\BankEmailImport;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Banking\BankEmailTransactionResource\Pages;
use App\Filament\Support\Units;
use App\Models\Banking\BankEmailTransaction;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Accounts → Bank e-mail transactions: what was read from bank notification e-mails, to check.
 * Confirm (choosing the party when it was only suggested), ignore, or send to the bank statement.
 */
class BankEmailTransactionResource extends Resource
{
    protected static ?string $model = BankEmailTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Bank e-mail transactions';

    protected static ?string $modelLabel = 'bank e-mail transaction';

    protected static ?string $slug = 'bank-email-transactions';

    protected static ?int $navigationSort = 66;

    public static function canViewAny(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ! $me->isPlatformAdmin() && $me->hasPermission('bank.view');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = BankEmailTransaction::query()->where('import_status', 'parsed')->where('reconciliation_status', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function amount(BankEmailTransaction $r): string
    {
        return $r->amount_paise === null ? '—' : ($r->direction === 'debit' ? '− ' : '+ ').Units::paiseToRupees($r->amount_paise);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['party', 'bankLedger']))
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('txn_date')->label('Date')->date('d M Y')->sortable()->description(fn (BankEmailTransaction $r) => $r->txn_time ? substr($r->txn_time, 0, 5) : null),
                TextColumn::make('bank_name')->label('Bank')->description(fn (BankEmailTransaction $r) => $r->account_hint),
                TextColumn::make('amount_paise')->label('Amount')->alignEnd()->fontFamily('mono')->state(fn (BankEmailTransaction $r) => self::amount($r))
                    ->color(fn (BankEmailTransaction $r) => $r->direction === 'credit' ? 'success' : 'danger'),
                TextColumn::make('counterparty')->label('Sender / receiver')->searchable()->placeholder('—')->description(fn (BankEmailTransaction $r) => $r->reference),
                TextColumn::make('party.name')->label('Party')->placeholder('—')
                    ->description(fn (BankEmailTransaction $r) => BankEmailTransaction::MATCH_LABELS[$r->match_status] ?? $r->match_status),
                TextColumn::make('import_status')->label('Import')->badge()->formatStateUsing(fn (string $state) => BankEmailTransaction::IMPORT_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'parsed' => 'gray', 'duplicate' => 'warning', default => 'danger',
                    })->toggleable(),
                TextColumn::make('reconciliation_status')->label('Status')->badge()->formatStateUsing(fn (string $state) => BankEmailTransaction::RECONCILIATION_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'on_statement' => 'success', 'confirmed' => 'info', 'ignored' => 'gray', default => 'warning',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('reconciliation_status')->label('Status')->options(BankEmailTransaction::RECONCILIATION_LABELS)->default('pending'),
                SelectFilter::make('match_status')->label('Party')->options(BankEmailTransaction::MATCH_LABELS),
                SelectFilter::make('import_status')->label('Import')->options(BankEmailTransaction::IMPORT_LABELS),
                SelectFilter::make('direction')->options(['credit' => 'Money in', 'debit' => 'Money out']),
                Filter::make('dates')->form([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('txn_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('txn_date', '<=', $d))),
            ])
            ->actions([
                ViewAction::make(),
                ...self::reviewActions(),
            ]);
    }

    /**
     * Confirm, ignore and send to statement: table row actions, or page actions on the record page.
     *
     * @param  class-string<Action>|class-string<\Filament\Actions\Action>  $class
     * @return list<Action|\Filament\Actions\Action>
     */
    public static function reviewActions(string $class = Action::class): array
    {
        $run = function (callable $callback, string $done): void {
            try {
                $callback(app(BankEmailImport::class));
                Notification::make()->success()->title($done)->send();
            } catch (BusinessRuleViolation $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        };
        $me = fn () => Filament::auth()->user();

        return [
            $class::make('confirm')->label('Confirm')->icon('heroicon-o-check')->color('success')
                ->visible(fn (BankEmailTransaction $r) => $r->import_status === 'parsed' && $r->reconciliation_status === 'pending')
                ->fillForm(fn (BankEmailTransaction $r) => ['party_id' => $r->party_id])
                ->form(fn (BankEmailTransaction $r) => [
                    Select::make('party_id')->label('Party')->searchable()
                        ->options(fn () => Party::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code'])
                            ->mapWithKeys(fn (Party $p) => [$p->id => $p->name.' ('.$p->code.')'])->all())
                        ->helperText($r->match_reason),
                ])
                ->modalDescription('Checked against the e-mail: the amount, the date and who it is from or to.')
                ->action(fn (BankEmailTransaction $record, array $data) => $run(
                    fn (BankEmailImport $i) => $i->confirm($record, filled($data['party_id'] ?? null) ? Party::query()->find($data['party_id']) : null, true),
                    'Confirmed',
                )),
            $class::make('statement')->label('To bank statement')->icon('heroicon-o-document-plus')->color('primary')
                ->visible(fn (BankEmailTransaction $r) => $r->reconciliation_status === 'confirmed' && ($me()?->can('ledger.post') ?? false))
                ->requiresConfirmation()
                ->modalDescription('Adds it to the bank statement of its account, where it is matched with the books.')
                ->action(fn (BankEmailTransaction $record) => $run(fn (BankEmailImport $i) => $i->toStatement($record), 'Added to the bank statement')),
            $class::make('ignore')->label('Ignore')->icon('heroicon-o-eye-slash')->color('gray')
                ->visible(fn (BankEmailTransaction $r) => in_array($r->reconciliation_status, ['pending', 'confirmed'], true))
                ->form([Textarea::make('reason')->required()->minLength(3)->maxLength(300)])
                ->action(fn (BankEmailTransaction $record, array $data) => $run(fn (BankEmailImport $i) => $i->ignore($record, (string) $data['reason']), 'Ignored')),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Transaction ID'),
                TextEntry::make('reference')->label('Bank reference')->placeholder('—'),
                TextEntry::make('txn_date')->label('Transaction date')->date('d M Y')->placeholder('—'),
                TextEntry::make('txn_time')->label('Transaction time')->placeholder('—'),
                TextEntry::make('bank_name')->label('Bank name')->placeholder('—'),
                TextEntry::make('bankLedger.name')->label('Bank account')->placeholder('—')->helperText(fn (BankEmailTransaction $r) => $r->account_hint),
                TextEntry::make('direction')->label('Debit / credit')->formatStateUsing(fn (?string $state) => $state === 'credit' ? 'Credit (money in)' : ($state === 'debit' ? 'Debit (money out)' : '—')),
                TextEntry::make('amount')->state(fn (BankEmailTransaction $r) => self::amount($r)),
                TextEntry::make('counterparty')->label('Sender / receiver')->placeholder('—'),
                TextEntry::make('counterparty_account')->label('Their account')->placeholder('—'),
                TextEntry::make('party.name')->label('Party')->placeholder('—'),
                TextEntry::make('party_id')->label('Party ID')->placeholder('—')->fontFamily('mono'),
                TextEntry::make('match_reason')->label('Party matching')->columnSpan(2)->placeholder('—'),
                TextEntry::make('import_status')->label('Import status')->formatStateUsing(fn (string $state) => BankEmailTransaction::IMPORT_LABELS[$state] ?? $state)
                    ->helperText(fn (BankEmailTransaction $r) => $r->parse_error),
                TextEntry::make('reconciliation_status')->label('Reconciliation status')->formatStateUsing(fn (string $state) => BankEmailTransaction::RECONCILIATION_LABELS[$state] ?? $state),
            ]),
            Section::make('Source e-mail')->columns(3)->schema([
                TextEntry::make('message_from')->label('From'),
                TextEntry::make('message_subject')->label('Subject'),
                TextEntry::make('received_at')->label('Received')->dateTime('d M Y H:i', 'Asia/Karachi')->placeholder('—'),
                TextEntry::make('message_id')->label('E-mail reference')->columnSpanFull()->fontFamily('mono'),
                TextEntry::make('excerpt')->label('Text read')->columnSpanFull()->placeholder('—')->extraAttributes(['class' => 'whitespace-pre-line text-sm']),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankEmailTransactions::route('/'),
            'view' => Pages\ViewBankEmailTransaction::route('/{record}'),
        ];
    }
}
