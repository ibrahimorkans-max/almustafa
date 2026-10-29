<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform;

use App\Domain\Banking\BankEmailParser;
use App\Domain\Banking\MailMessage;
use App\Filament\Resources\Platform\BankEmailTemplateResource\Pages;
use App\Filament\Support\Units;
use App\Models\Banking\BankEmailTemplate;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform → Bank e-mail templates: how each bank's notification e-mail is read, for every customer.
 * "Test" reads a pasted e-mail with the template, so a pattern is checked before customers rely on it.
 */
class BankEmailTemplateResource extends Resource
{
    protected static ?string $model = BankEmailTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Bank e-mail templates';

    protected static ?string $modelLabel = 'bank e-mail template';

    protected static ?string $slug = 'platform/bank-email-templates';

    protected static ?int $navigationSort = 31;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        $regex = fn (string $name, string $label, string $hint = '') => TextInput::make($name)->label($label)->maxLength(300)->helperText($hint)
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && $value !== '' && @preg_match('~'.str_replace('~', '\~', (string) $value).'~iu', '') === false) {
                    $fail('Not a valid regular expression.');
                }
            });

        return $form->columns(2)->schema([
            TextInput::make('bank_name')->required()->maxLength(80),
            Toggle::make('active')->default(true)->inline(false),
            Section::make('Which e-mails')->columns(2)->schema([
                $regex('sender_pattern', 'Sender (From) pattern', 'e.g. alerts@hbl\.com')->required(),
                $regex('subject_pattern', 'Subject pattern (optional)', 'e.g. Transaction Alert'),
            ]),
            Section::make('What to read')->columns(2)
                ->description('Regular expressions, case-insensitive. Put the wanted part in a group: (?<amount>…) for the amount, (?<value>…) or the first group for the others.')
                ->schema([
                    $regex('amount_pattern', 'Amount', 'e.g. PKR\s*(?<amount>[\d,]+\.?\d*)')->required(),
                    $regex('credit_pattern', 'Money in when it matches', 'e.g. credited|received'),
                    $regex('debit_pattern', 'Money out when it matches', 'e.g. debited|withdrawn'),
                    $regex('reference_pattern', 'Bank reference', 'e.g. Ref(?:erence)?\s*(?:No\.?)?\s*[:#]?\s*(\w+)'),
                    $regex('account_pattern', 'Our account (as printed)', 'e.g. A/C\s*(\*+\d{4})'),
                    $regex('counterparty_pattern', 'Sender / receiver name', 'e.g. from\s+([A-Z][\w &.]+?)\s+(?:on|A/C)'),
                    $regex('counterparty_account_pattern', 'Sender / receiver account or IBAN', 'e.g. (PK\d{2}[A-Z]{4}\d{16})'),
                    $regex('date_pattern', 'Date and time', 'e.g. on\s+(\d{2}-\w{3}-\d{4} \d{2}:\d{2})'),
                    TextInput::make('date_format')->label('Date format (PHP)')->maxLength(40)->placeholder('d-M-Y H:i')
                        ->helperText('Empty: the date is read as written; the e-mail\'s own time is used when no date is found.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bank_name')->searchable()->sortable(),
                TextColumn::make('sender_pattern')->label('Sender')->fontFamily('mono'),
                IconColumn::make('active')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->since(),
            ])
            ->defaultSort('bank_name')
            ->actions([
                Action::make('test')->label('Test with an e-mail')->icon('heroicon-o-beaker')
                    ->form([
                        TextInput::make('from')->required()->placeholder('alerts@bank.com'),
                        TextInput::make('subject'),
                        Textarea::make('text')->label('E-mail text')->required()->rows(8),
                    ])
                    ->modalSubmitActionLabel('Read it')
                    ->action(function (BankEmailTemplate $record, array $data): void {
                        $r = app(BankEmailParser::class)->parse(new MailMessage('test', (string) $data['from'], (string) ($data['subject'] ?? ''),
                            CarbonImmutable::now(), MailMessage::textOf((string) $data['text'], false)), [$record]);
                        if ($r['template'] === null) {
                            Notification::make()->warning()->title('This template does not apply to that sender or subject.')->send();

                            return;
                        }
                        $f = $r['fields'];
                        $body = collect([
                            'Amount' => $f['amount_paise'] === null ? '—' : 'Rs '.Units::paiseToRupees((int) $f['amount_paise']),
                            'Direction' => $f['direction'] ?? '—', 'Date' => trim(($f['txn_date'] ?? '').' '.($f['txn_time'] ?? '')) ?: '—',
                            'Reference' => $f['reference'] ?? '—', 'Our account' => $f['account_hint'] ?? '—',
                            'Sender / receiver' => $f['counterparty'] ?? '—', 'Their account' => $f['counterparty_account'] ?? '—',
                        ])->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ');
                        Notification::make()->title($r['error'] ?? 'Read correctly')->body($body)->color($r['error'] ? 'danger' : 'success')
                            ->icon($r['error'] ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')->persistent()->send();
                    }),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageBankEmailTemplates::route('/')];
    }
}
