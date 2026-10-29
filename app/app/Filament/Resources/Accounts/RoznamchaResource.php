<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Domain\Security\EntryLocation;
use App\Filament\Resources\Accounts\RoznamchaResource\Pages;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\RoznamchaLine;
use App\Models\Accounts\RoznamchaPage;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Accounts → Roznamcha: the daily cash book, scanned or typed, checked by a second person, then posted. */
class RoznamchaResource extends Resource
{
    protected static ?string $model = RoznamchaPage::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Roznamcha';

    protected static ?string $modelLabel = 'roznamcha page';

    protected static ?string $slug = 'roznamcha';

    protected static ?int $navigationSort = 25;

    public static function canViewAny(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ! $me->isPlatformAdmin() && ($me->hasPermission('ledger.view') || $me->hasPermission('ledger.post'));
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->hasPermission('ledger.post');
    }

    public static function form(Form $form): Form
    {
        $today = fn () => now('Asia/Karachi');

        return $form->columns(2)->schema([
            DatePicker::make('entry_date')->label('Entry date')->required()->default($today)->maxDate($today),
            Select::make('cash_ledger_id')->label('Cash account')->required()
                ->options(fn () => LedgerAccount::query()->where('kind', 'cash')->where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                ->default(fn () => LedgerAccount::query()->where('kind', 'cash')->where('status', 'active')->count() === 1
                    ? LedgerAccount::query()->where('kind', 'cash')->where('status', 'active')->value('id') : null),
            Radio::make('source')->label('Input')->options(RoznamchaPage::SOURCES)->default('camera')->required()->live()->inline()->columnSpanFull()
                ->afterStateUpdated(fn (Set $set) => $set('scan', null)),
            // Mobile camera: on a phone the camera opens; on a computer, a QR code / link opens it on a phone.
            Hidden::make('capture_token'),
            Hidden::make('capture_file_id'),
            ViewField::make('camera_panel')->hiddenLabel()->view('filament.forms.roznamcha-camera')->dehydrated(false)->columnSpanFull()
                ->visible(fn (Get $get) => $get('source') === 'camera'),
            // Scanner: the mill server reads the page from the network scanner.
            Hidden::make('scanned_file_id'),
            ViewField::make('scanner_panel')->hiddenLabel()->view('filament.forms.roznamcha-scanner')->dehydrated(false)->columnSpanFull()
                ->visible(fn (Get $get) => $get('source') === 'scanner'),
            FileUpload::make('scan')->storeFiles(false)->maxSize(5120)->columnSpanFull()
                ->label(fn (Get $get) => match ($get('source')) {
                    'camera' => 'Or take the photo on this device', 'scanner' => 'Or choose the file your scanner saved', 'pdf' => 'PDF of the page', default => 'Picture of the page',
                })
                ->acceptedFileTypes(fn (Get $get) => match ($get('source')) {
                    'pdf' => ['application/pdf'], 'scanner' => ['image/jpeg', 'image/png', 'application/pdf'], default => ['image/jpeg', 'image/png'],
                })
                ->extraInputAttributes(fn (Get $get) => $get('source') === 'camera' ? ['capture' => 'environment'] : [])
                ->visible(fn (Get $get) => $get('source') !== 'manual')
                ->required(fn (Get $get) => match ($get('source')) {
                    'camera' => blank($get('capture_file_id')), 'scanner' => blank($get('scanned_file_id')), 'manual' => false, default => true,
                }),
            // Typed by hand: the entries straight away.
            Group::make(self::lineFields())->columnSpanFull()->visible(fn (Get $get) => $get('source') === 'manual'),
            Textarea::make('note')->maxLength(1000)->columnSpanFull(),
            ...EntryLocation::fields(),
        ]);
    }

    /** @return list<Component> the lines, corrected while the page is to check */
    public static function lineFields(): array
    {
        return [
            Repeater::make('lines')->label('Entries')->columns(6)->reorderable()->addActionLabel('Add an entry')->defaultItems(0)
                ->schema([
                    Select::make('party_id')->label('Party')->searchable()->columnSpan(2)
                        ->options(fn () => Party::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                    Select::make('ledger_account_id')->label('or account')->searchable()->columnSpan(2)
                        ->options(fn () => LedgerAccount::query()->whereNotIn('kind', ['cash', 'bank', 'party'])->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                    TextInput::make('credit')->label('Received (jama)')->regex('/^[\d,]{1,16}(\.\d{1,2})?$/'),
                    TextInput::make('debit')->label('Paid (banam)')->regex('/^[\d,]{1,16}(\.\d{1,2})?$/'),
                    TextInput::make('account_text')->label('As written')->columnSpan(2)->maxLength(160),
                    TextInput::make('narration')->columnSpan(3)->maxLength(300),
                    TextInput::make('reference')->maxLength(80),
                ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function linesForForm(RoznamchaPage $page): array
    {
        return $page->lines()->get()->map(fn (RoznamchaLine $l) => [
            'party_id' => $l->party_id, 'ledger_account_id' => $l->ledger_account_id, 'account_text' => $l->account_text,
            'credit' => $l->credit_paise > 0 ? bcdiv((string) $l->credit_paise, '100', 2) : null,
            'debit' => $l->debit_paise > 0 ? bcdiv((string) $l->debit_paise, '100', 2) : null,
            'narration' => $l->narration, 'reference' => $l->reference,
        ])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withSum('lines as received_paise', 'credit_paise')->withSum('lines as paid_paise', 'debit_paise')->withCount('lines'))
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('entry_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('source')->formatStateUsing(fn (string $state) => RoznamchaPage::SOURCES[$state] ?? $state)
                    ->description(fn (RoznamchaPage $r) => $r->ocr_engine && $r->ocr_engine !== 'none' ? 'Read by '.$r->ocr_engine : null),
                TextColumn::make('lines_count')->label('Entries')->alignEnd(),
                TextColumn::make('received_paise')->label('Received')->alignEnd()->fontFamily('mono')->formatStateUsing(fn ($state) => Units::paiseToRupees((int) $state)),
                TextColumn::make('paid_paise')->label('Paid')->alignEnd()->fontFamily('mono')->formatStateUsing(fn ($state) => Units::paiseToRupees((int) $state)),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => RoznamchaPage::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'posted' => 'success', 'verified' => 'info', default => 'warning',
                    }),
            ])
            ->defaultSort('entry_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(RoznamchaPage::STATUS_LABELS),
                Filter::make('dates')->form([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '<=', $d))),
            ])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Roznamcha number'),
                TextEntry::make('entry_date')->date('d M Y'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => RoznamchaPage::STATUS_LABELS[$state] ?? $state),
                TextEntry::make('source')->formatStateUsing(fn (string $state) => RoznamchaPage::SOURCES[$state] ?? $state),
                TextEntry::make('scan')->label('Attached scan')->state(fn (RoznamchaPage $r) => $r->stored_file_id ? 'Open the original page' : null)->placeholder('None')
                    ->url(fn (RoznamchaPage $r) => $r->stored_file_id ? route('filament.admin.roznamcha.scan', ['id' => $r->id]) : null, shouldOpenInNewTab: true)->color('primary'),
                TextEntry::make('entered_by')->label('Entered by')->state(fn (RoznamchaPage $r) => self::username($r->created_by))->placeholder('—'),
                TextEntry::make('verified_by')->label('Verified by')->state(fn (RoznamchaPage $r) => self::username($r->verified_by))->placeholder('—')
                    ->helperText(fn (RoznamchaPage $r) => $r->verified_at?->timezone('Asia/Karachi')->format('d M Y H:i')),
                TextEntry::make('cashLedger.name')->label('Cash account')->placeholder('—'),
                TextEntry::make('captured_location')->label('Entered at')
                    ->state(fn (RoznamchaPage $r) => EntryLocation::label($r->captured_location))->url(fn (RoznamchaPage $r) => EntryLocation::mapUrl($r->captured_location), shouldOpenInNewTab: true)
                    ->visible(fn (RoznamchaPage $r) => $r->captured_location !== null)->color('primary'),
                TextEntry::make('note')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make('Entries')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('account')->label('Party / account')->columnSpan(2)
                        ->state(fn (RoznamchaLine $record) => $record->party->name ?? $record->ledgerAccount->name ?? null)->placeholder('Not chosen')
                        ->helperText(fn (RoznamchaLine $record) => $record->account_text),
                    TextEntry::make('credit_paise')->label('Received')->formatStateUsing(fn (int $state) => $state > 0 ? Units::paiseToRupees($state) : '—'),
                    TextEntry::make('debit_paise')->label('Paid')->formatStateUsing(fn (int $state) => $state > 0 ? Units::paiseToRupees($state) : '—'),
                    TextEntry::make('narration')->placeholder('—'),
                    TextEntry::make('voucher.number')->label('Voucher')->placeholder('—'),
                ]),
            ]),
            Section::make('Text read from the scan')->collapsed()->schema([
                TextEntry::make('ocr_text')->hiddenLabel()->placeholder('Nothing read.')->extraAttributes(['class' => 'whitespace-pre-line text-sm']),
            ]),
        ]);
    }

    private static function username(?string $accountId): ?string
    {
        return $accountId === null ? null : LoginAccount::query()->whereKey($accountId)->value('username');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoznamchaPages::route('/'),
            'create' => Pages\CreateRoznamchaPage::route('/create'),
            'view' => Pages\ViewRoznamchaPage::route('/{record}'),
        ];
    }
}
