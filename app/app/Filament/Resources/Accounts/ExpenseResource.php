<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Domain\Accounts\Expenses;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\ExpenseResource\Pages;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\Accounts\Expense;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\LedgerAccount;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/** Expenses and other income against categories; drafts (typed in or from recurring templates) are posted by accounts. */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Expenses & income';

    protected static ?string $modelLabel = 'expense or income';

    protected static ?string $pluralModelLabel = 'expenses and income';

    protected static ?string $slug = 'expenses';

    protected static ?int $navigationSort = 30;

    public static function getNavigationBadge(): ?string
    {
        $n = Expense::query()->where('status', Expense::DRAFT)->count();

        return $n > 0 ? (string) $n : null;
    }

    /** @return array<string, string> */
    public static function cashLedgers(): array
    {
        return LedgerAccount::query()->whereIn('kind', ['cash', 'bank'])->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    public static function parties(): array
    {
        return Party::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Form $form): Form
    {
        $today = fn () => now('Asia/Karachi');

        return $form->columns(2)->schema([
            Select::make('expense_category_id')->label('Category')->required()->searchable()->live()
                ->options(fn () => ExpenseCategory::query()->where('status', 'active')->orderBy('kind')->orderBy('name')->get()
                    ->mapWithKeys(fn (ExpenseCategory $c) => [$c->id => ($c->kind === 'income' ? 'Income: ' : '').$c->name])->all()),
            DatePicker::make('entry_date')->label('Date')->required()->default($today)->maxDate($today),
            TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
            TextInput::make('description')->required()->minLength(3)->maxLength(300),
            Radio::make('via')->label(fn (Get $get) => self::isIncome($get('expense_category_id')) ? 'Received' : 'Paid')->inline()->required()->default('cash')->live()
                ->options(['cash' => 'Cash or bank', 'party' => 'On credit (a party)']),
            Select::make('cash_ledger_id')->label('Cash or bank account')->options(fn () => self::cashLedgers())
                ->visible(fn (Get $get) => $get('via') === 'cash')->required(fn (Get $get) => $get('via') === 'cash'),
            Select::make('party_id')->label('Party')->searchable()->options(fn () => self::parties())
                ->visible(fn (Get $get) => $get('via') === 'party')->required(fn (Get $get) => $get('via') === 'party'),
            Select::make('gate_entry_id')->label('For vehicle (optional)')->searchable()->live()
                ->helperText('Freight, unloading or handling of one paddy vehicle.')
                ->options(fn () => GateEntry::query()->where('direction', 'inbound')->whereNull('archived_at')
                    ->where('arrived_at', '>=', now()->subDays(60))->orderByDesc('arrived_at')->limit(200)->get()
                    ->mapWithKeys(fn (GateEntry $e) => [$e->id => $e->registration_no.' · '.$e->number])->all()),
            Toggle::make('capitalize_to_stock')->label('Add to this lot\'s stock cost (landed cost)')
                ->helperText('For costs of bringing the paddy in (freight, unloading). Its stock value includes it.')
                ->visible(fn (Get $get) => filled($get('gate_entry_id')) && ! self::isIncome($get('expense_category_id'))),
            FileUpload::make('receipt')->label('Receipt or bill')->storeFiles(false)->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(5120),
            Toggle::make('post')->label('Post it to the books now')->default(true)->helperText('Off: keep it as a draft to post later.'),
        ]);
    }

    public static function isIncome(?string $categoryId): bool
    {
        return $categoryId !== null && ExpenseCategory::query()->whereKey($categoryId)->value('kind') === 'income';
    }

    public static function table(Table $table): Table
    {
        $rs = fn (?int $state) => Units::paiseToRupees($state);
        $can = fn (): bool => Filament::auth()->user() instanceof LoginAccount && Filament::auth()->user()->hasPermission('ledger.post');
        $run = fn (callable $do, string $ok) => function (Expense $record, array $data = []) use ($do, $ok): void {
            try {
                $do($record, $data);
                Notification::make()->title($ok)->success()->send();
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };
        $svc = fn () => app(Expenses::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'cashLedger', 'party', 'voucher']))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('entry_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('category.name')->label('Category')
                    ->description(fn (Expense $r) => $r->kind === 'income' ? 'Income' : null),
                TextColumn::make('description')->limit(40)->searchable(),
                TextColumn::make('via')->label('Paid / received')
                    ->state(fn (Expense $r) => $r->cashLedger->name ?? ($r->party !== null ? 'On credit: '.$r->party->name : '—')),
                TextColumn::make('amount_paise')->label('Amount')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('voucher.number')->label('Voucher')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => Expense::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Expense::DRAFT => 'warning', Expense::POSTED => 'success', default => 'gray',
                    }),
            ])
            ->defaultSort('entry_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(Expense::STATUSES),
                SelectFilter::make('kind')->options(['expense' => 'Expenses', 'income' => 'Income']),
                SelectFilter::make('expense_category_id')->label('Category')->options(fn () => ExpenseCategory::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                PrintButton::row('expense', fn (Expense $r) => $r->status === Expense::POSTED),
                Action::make('receipt')->icon('heroicon-o-paper-clip')->color('gray')->iconButton()->tooltip('Receipt')
                    ->visible(fn (Expense $r) => $r->receipt_file_id !== null)
                    ->action(function (Expense $record) {
                        $file = $record->receipt;

                        return $file === null ? null : response()->streamDownload(fn () => print (Storage::disk('local')->get($file->path)),
                            "receipt-{$record->number}.".($file->mime === 'application/pdf' ? 'pdf' : ($file->mime === 'image/png' ? 'png' : 'jpg')), ['Content-Type' => $file->mime]);
                    }),
                Action::make('post')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Expense $r) => $r->status === Expense::DRAFT && $can())
                    ->modalDescription('Check the amount and how it was paid; posting makes the voucher.')
                    ->fillForm(fn (Expense $record) => ['amount' => bcdiv((string) $record->amount_paise, '100', 2), 'via' => $record->party_id !== null ? 'party' : 'cash',
                        'cash_ledger_id' => $record->cash_ledger_id, 'party_id' => $record->party_id])
                    ->form([
                        TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
                        Radio::make('via')->label('Paid / received')->inline()->required()->live()->options(['cash' => 'Cash or bank', 'party' => 'On credit (a party)']),
                        Select::make('cash_ledger_id')->label('Cash or bank account')->options(fn () => self::cashLedgers())
                            ->visible(fn (Get $get) => $get('via') === 'cash')->required(fn (Get $get) => $get('via') === 'cash'),
                        Select::make('party_id')->label('Party')->searchable()->options(fn () => self::parties())
                            ->visible(fn (Get $get) => $get('via') === 'party')->required(fn (Get $get) => $get('via') === 'party'),
                    ])
                    ->action($run(fn (Expense $r, array $d) => $svc()->post($r, Units::rupeesToPaise((string) $d['amount']),
                        $d['via'] === 'cash' ? LedgerAccount::query()->find($d['cash_ledger_id']) : null,
                        $d['via'] === 'party' ? Party::query()->find($d['party_id']) : null), 'Posted.')),
                Action::make('cancel')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Expense $r) => $r->status === Expense::DRAFT && $can())
                    ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                    ->action($run(fn (Expense $r, array $d) => $svc()->cancel($r, (string) $d['reason']), 'Draft cancelled.')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
        ];
    }
}
