<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Hr\Reimbursements;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\ReimbursementResource\Pages;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\Units;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\Employee;
use App\Models\Hr\Reimbursement;
use App\Models\Hr\ReimbursementLine;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Staff expense claims: submitted with receipts, checked by a supervisor, approved by accounts, then paid. */
class ReimbursementResource extends Resource
{
    protected static ?string $model = Reimbursement::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Staff claims';

    protected static ?string $modelLabel = 'staff claim';

    protected static ?string $slug = 'hr/staff-claims';

    protected static ?int $navigationSort = 32;

    /** The claims waiting for the signed-in person's step. */
    public static function getNavigationBadge(): ?string
    {
        $user = Filament::auth()->user();
        if (! $user instanceof LoginAccount) {
            return null;
        }
        $waiting = array_keys(array_filter([
            Reimbursement::SUBMITTED => $user->hasPermission('hr.attendance.review') || $user->hasPermission('hr.leave.approve'),
            Reimbursement::CHECKED => $user->hasPermission('ledger.post'),
            Reimbursement::APPROVED => $user->hasPermission('payment.create'),
        ]));
        $n = $waiting === [] ? 0 : Reimbursement::query()->whereIn('status', $waiting)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function form(Form $form): Form
    {
        $today = fn () => now('Asia/Karachi');

        return $form->columns(2)->schema([
            Select::make('employee_id')->label('Employee')->required()->searchable()
                ->options(fn () => Employee::query()->with('person')->where('status', '!=', Employee::EXITED)->orderBy('code')->get()
                    ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} {$e->name()}"])->all()),
            TextInput::make('purpose')->label('What the expenses were for')->required()->minLength(5)->maxLength(500),
            Repeater::make('lines')->label('Expenses')->columnSpanFull()->required()->minItems(1)->maxItems(30)->defaultItems(1)->columns(5)
                ->schema([
                    Select::make('category_id')->label('Category')->required()
                        ->options(fn () => ExpenseCategory::query()->where('status', 'active')->where('claimable', true)->orderBy('name')->pluck('name', 'id')),
                    DatePicker::make('spent_on')->label('Date')->required()->default($today)->maxDate($today)
                        ->minDate(fn () => now('Asia/Karachi')->subDays(90)),
                    TextInput::make('description')->required()->minLength(3)->maxLength(200),
                    TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
                    FileUpload::make('receipt')->label('Receipt')->storeFiles(false)->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(5120)
                        ->helperText('Needed above '.Money::paise(Reimbursements::RECEIPT_REQUIRED_ABOVE_PAISE)->format()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $rs = fn (?int $state) => Units::paiseToRupees($state);
        $can = fn (string ...$p): bool => Filament::auth()->user() instanceof LoginAccount
            && array_any($p, fn (string $x) => Filament::auth()->user()->hasPermission($x));
        $run = fn (callable $do, string $ok) => function (Reimbursement $record, array $data = []) use ($do, $ok): void {
            try {
                $do($record, $data);
                Notification::make()->title($ok)->success()->send();
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };
        $svc = fn () => app(Reimbursements::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.person'])->withCount('lines'))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('claim_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('employee.code')->label('Employee')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Name'),
                TextColumn::make('purpose')->limit(40)->tooltip(fn (Reimbursement $r) => $r->purpose),
                TextColumn::make('lines_count')->label('Lines')->alignEnd(),
                TextColumn::make('total_paise')->label('Total')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => Reimbursement::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Reimbursement::SUBMITTED, Reimbursement::CHECKED => 'warning', Reimbursement::APPROVED => 'info',
                        Reimbursement::PAID => 'success', Reimbursement::REJECTED => 'danger', default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->options(Reimbursement::STATUSES)])
            ->actions([
                ViewAction::make(),
                Action::make('voucher')->icon('heroicon-o-printer')->color('gray')
                    ->visible(fn (Reimbursement $r) => in_array($r->status, [Reimbursement::APPROVED, Reimbursement::PAID], true) && DocumentActions::allowed())
                    ->form(DocumentActions::form('reimbursement_voucher'))
                    ->action(fn (Reimbursement $record, array $data) => DocumentActions::run('reimbursement_voucher', $record->id, $data)),
                Action::make('check')->label('Check')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Reimbursement $r) => $r->status === Reimbursement::SUBMITTED && $can('hr.attendance.review', 'hr.leave.approve'))
                    ->modalDescription('Confirm the expenses were real and for the mill\'s work.')
                    ->form([Textarea::make('note')->maxLength(500)])
                    ->action($run(fn (Reimbursement $r, array $d) => $svc()->check($r, $d['note'] ?? null), 'Claim checked; it goes to accounts.')),
                Action::make('approve')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (Reimbursement $r) => $r->status === Reimbursement::CHECKED && $can('ledger.post'))
                    ->modalDescription('Posts each line to its expense ledger, owed to the employee.')
                    ->form([Textarea::make('note')->maxLength(500)])
                    ->action($run(fn (Reimbursement $r, array $d) => $svc()->approve($r, $d['note'] ?? null), 'Claim approved and posted.')),
                Action::make('reject')->label('Refuse')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Reimbursement $r) => in_array($r->status, [Reimbursement::SUBMITTED, Reimbursement::CHECKED], true)
                        && $can('hr.attendance.review', 'hr.leave.approve', 'ledger.post'))
                    ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                    ->action($run(fn (Reimbursement $r, array $d) => $svc()->reject($r, (string) $d['reason']), 'Claim refused.')),
                Action::make('pay')->icon('heroicon-o-banknotes')->color('primary')
                    ->visible(fn (Reimbursement $r) => $r->status === Reimbursement::APPROVED && $can('payment.create'))
                    ->form([
                        Select::make('from')->label('Paid from')->required()
                            ->options(fn () => LedgerAccount::query()->whereIn('kind', ['cash', 'bank'])->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                        DatePicker::make('date')->required()->default(now('Asia/Karachi'))->maxDate(now('Asia/Karachi')),
                    ])
                    ->action($run(fn (Reimbursement $r, array $d) => $svc()->pay($r, LedgerAccount::query()->findOrFail($d['from']),
                        CarbonImmutable::parse($d['date'], 'Asia/Karachi')), 'Claim paid and posted.')),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $rs = fn (?int $state) => Units::paiseToRupees($state);

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('employee.code')->label('Employee')
                    ->formatStateUsing(fn (Reimbursement $record) => "{$record->employee->code} {$record->employee->name()}"),
                TextEntry::make('claim_date')->label('Date')->date('d M Y'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => Reimbursement::STATUSES[$state] ?? $state),
                TextEntry::make('purpose')->columnSpan(2),
                TextEntry::make('total_paise')->label('Total')->formatStateUsing($rs),
                TextEntry::make('decision_note')->label('Decision note')->placeholder('—'),
            ]),
            Section::make('Expenses')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('spent_on')->label('Date')->date('d M Y'),
                    TextEntry::make('category.name')->label('Category'),
                    TextEntry::make('description'),
                    TextEntry::make('amount_paise')->label('Amount')->formatStateUsing($rs),
                    TextEntry::make('receipt_file_id')->label('Receipt')->placeholder('none')
                        ->formatStateUsing(fn (ReimbursementLine $record) => $record->receipt?->mime === 'application/pdf' ? 'PDF' : 'Photo')
                        ->suffixAction(InfolistAction::make('download')->icon('heroicon-o-arrow-down-tray')
                            ->visible(fn (ReimbursementLine $record) => $record->receipt_file_id !== null)
                            ->action(fn (ReimbursementLine $record) => self::receipt($record))),
                ]),
            ]),
        ]);
    }

    /** Streams one receipt; only someone who may see the claim reaches the view page. */
    public static function receipt(ReimbursementLine $line): ?StreamedResponse
    {
        $file = $line->receipt;
        if ($file === null) {
            return null;
        }
        $ext = match ($file->mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', default => 'jpg',
        };

        return response()->streamDownload(fn () => print (Storage::disk('local')->get($file->path)), "receipt-{$line->id}.{$ext}", ['Content-Type' => $file->mime]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReimbursements::route('/'),
            'create' => Pages\CreateReimbursement::route('/create'),
            'view' => Pages\ViewReimbursement::route('/{record}'),
        ];
    }
}
