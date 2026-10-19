<?php

declare(strict_types=1);

namespace App\Filament\Resources\Claims;

use App\Filament\Resources\Claims\ClaimResource\Pages;
use App\Filament\Support\HidesArchived;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\Accounts\PurchaseBill;
use App\Models\Claims\Claim;
use App\Models\Claims\ClaimEvent;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ClaimResource extends Resource
{
    use HidesArchived;

    protected static ?string $model = Claim::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-circle';

    protected static ?string $navigationGroup = 'Claims & pricing';

    protected static ?string $navigationLabel = 'Claims';

    protected static ?string $slug = 'claims';

    protected static ?int $navigationSort = 10;

    /** @var array<string, string> */
    public const STATUS_LABELS = ['open' => 'Open', 'approved' => 'Approved (to settle)', 'settled' => 'Settled', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'settled' => 'success', 'approved' => 'info', 'rejected', 'withdrawn' => 'danger', default => 'warning',
        };
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Radio::make('document_type')->label('Against')->required()->live()->inline()
                ->options([Claim::SALES => 'Our sales invoice (buyer claims)', Claim::PURCHASE => 'A purchase bill (we claim from the supplier)'])
                ->default(Claim::SALES)->columnSpanFull(),
            Select::make('document_id')->label('Document')->required()->searchable()
                ->options(fn (Get $get) => $get('document_type') === Claim::PURCHASE
                    ? PurchaseBill::query()->where('status', 'posted')->with('party')->orderByDesc('bill_date')->limit(300)->get()
                        ->mapWithKeys(fn (PurchaseBill $b) => [$b->id => "{$b->number} — {$b->party->name} · ".Units::paiseToRupees($b->net_paise)])
                    : SalesBill::query()->where('status', 'posted')->with('party')->orderByDesc('bill_date')->limit(300)->get()
                        ->mapWithKeys(fn (SalesBill $b) => [$b->id => "{$b->number} — {$b->party->name} · ".Units::paiseToRupees($b->total_paise)])),
            Select::make('type')->options(Claim::TYPES)->required(),
            TextInput::make('amount')->label('Amount claimed (Rs)')->required()->regex(Units::DECIMAL_2),
            Select::make('transporter_party_id')->label('Transporter (transit shortage)')->searchable()
                ->options(fn () => Party::query()->where('is_transporter', true)->orderBy('name')->pluck('name', 'id')),
            Textarea::make('description')->required()->minLength(10)->maxLength(2000)->columnSpanFull(),
            FileUpload::make('evidence')->label('Photos / documents')->multiple()->storeFiles(false)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(5120)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('party'))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('created_at')->label('Raised')->date('d M Y')->sortable(),
                TextColumn::make('party.name')->label('Party')->searchable(),
                TextColumn::make('document_type')->label('Against')->formatStateUsing(fn (string $state) => $state === Claim::SALES ? 'Sales invoice' : 'Purchase bill'),
                TextColumn::make('type')->formatStateUsing(fn (string $state) => Claim::TYPES[$state] ?? $state),
                TextColumn::make('claimed_paise')->label('Claimed')->alignEnd()->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextColumn::make('settled_paise')->label('Settled')->alignEnd()->formatStateUsing(fn (?int $state) => Units::paiseToRupees($state))->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(self::STATUS_LABELS),
            ])
            ->actions([ViewAction::make(), PrintButton::row('claim')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
                TextEntry::make('party.name')->label('Party'),
                TextEntry::make('document')->label('Against')->state(fn (Claim $r) => $r->document()?->number),
                TextEntry::make('type')->formatStateUsing(fn (string $state) => Claim::TYPES[$state] ?? $state),
                TextEntry::make('claimed_paise')->label('Claimed')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextEntry::make('settled_paise')->label('Approved / settled')->formatStateUsing(fn (?int $state) => Units::paiseToRupees($state))->placeholder('—'),
                TextEntry::make('transporter.name')->label('Transporter')->placeholder('—'),
                TextEntry::make('description')->columnSpanFull(),
                TextEntry::make('evidence')->label('Evidence (SHA-256)')->columnSpanFull()->badge()->placeholder('none')
                    ->formatStateUsing(fn (string $state) => substr($state, 0, 16).'…'),
            ]),
            Section::make('History')->schema([
                RepeatableEntry::make('events')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->label('When')->dateTime('d M Y H:i'),
                    TextEntry::make('event')->badge(),
                    TextEntry::make('actor.username')->label('Who')->placeholder('system'),
                    TextEntry::make('data')->label('Details')
                        ->state(fn (ClaimEvent $record) => collect($record->data ?? [])->except(['evidence'])->map(fn ($v, $k) => "{$k}: ".(is_scalar($v) ? $v : json_encode($v)))->implode(' · ')),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClaims::route('/'),
            'create' => Pages\CreateClaim::route('/create'),
            'view' => Pages\ViewClaim::route('/{record}'),
        ];
    }
}
