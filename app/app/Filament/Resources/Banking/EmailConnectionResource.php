<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banking;

use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\BankEmailImport;
use App\Domain\Banking\MailOAuth;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\Banking\EmailConnectionResource\Pages;
use App\Models\Accounts\LedgerAccount;
use App\Models\Banking\EmailConnection;
use App\Models\Banking\EmailSyncRun;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Accounts → Bank e-mail: the mailboxes a mill connected so its bank's notification e-mails are read.
 * Set by the Owner or the Super Admin ('settings.manage'). Only reading is ever asked for.
 */
class EmailConnectionResource extends Resource
{
    protected static ?string $model = EmailConnection::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Bank e-mail';

    protected static ?string $modelLabel = 'mailbox';

    protected static ?string $slug = 'bank-email';

    protected static ?int $navigationSort = 65;

    public static function canViewAny(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ! $me->isPlatformAdmin() && $me->hasPermission('settings.manage');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && BankEmailImport::enabledFor(app(TenantContext::class)->entityId());
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return array<string, string> the providers this server can sign in with */
    public static function providers(): array
    {
        return array_filter(EmailConnection::PROVIDERS, fn (string $label, string $key) => $key === 'imap'
            ? (bool) config('ricemill.bank_email.imap') : MailOAuth::configured($key), ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<int, string> */
    public static function intervals(): array
    {
        $min = (int) config('ricemill.bank_email.min_sync_minutes', 15);

        return array_filter([15 => 'Every 15 minutes', 30 => 'Every 30 minutes', 60 => 'Every hour', 180 => 'Every 3 hours', 720 => 'Twice a day', 1440 => 'Once a day'],
            fn (int $m) => $m >= $min, ARRAY_FILTER_USE_KEY);
    }

    /** @return list<Component> */
    public static function fields(bool $creating): array
    {
        $imap = fn (Get $get) => $get('provider') === 'imap';

        return array_values(array_filter([
            $creating ? Select::make('provider')->label('Email provider')->options(fn () => self::providers())->required()->live()
                ->helperText('Gmail and Outlook are signed in on their own page; nothing but reading mail is allowed.') : null,
            $creating ? TextInput::make('email')->label('Email address')->email()->required()->maxLength(160) : null,
            Select::make('ledger_account_id')->label('Associated bank account')->required()
                ->options(fn () => LedgerAccount::query()->where('kind', 'bank')->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
            Select::make('sync_minutes')->label('Sync frequency')->options(fn () => self::intervals())->default(30)->required(),
            $creating ? TextInput::make('imap_host')->label('IMAP server')->placeholder('imap.example.com')->visible($imap)->required($imap)->maxLength(120) : null,
            $creating ? TextInput::make('imap_port')->label('Port')->integer()->default(993)->visible($imap)->required($imap) : null,
            $creating ? TextInput::make('imap_username')->label('IMAP username')->visible($imap)->required($imap)->maxLength(160) : null,
            $creating ? TextInput::make('imap_password')->label('App password')->password()->revealable(false)->visible($imap)->required($imap)->maxLength(200)
                ->helperText('An app password made for this, not the mailbox\'s main password. It is kept encrypted and never shown again.') : null,
        ]));
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema(self::fields(false));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('bankLedger'))
            ->columns([
                TextColumn::make('email')->label('Email address')->searchable()->description(fn (EmailConnection $r) => EmailConnection::PROVIDERS[$r->provider] ?? $r->provider),
                TextColumn::make('bankLedger.name')->label('Bank account')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => EmailConnection::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'connected' => 'success', 'error' => 'danger', default => 'gray',
                    })
                    ->description(fn (EmailConnection $r) => $r->last_error),
                TextColumn::make('sync_minutes')->label('Sync')->formatStateUsing(fn (int $state) => self::intervals()[$state] ?? "Every {$state} minutes"),
                TextColumn::make('last_success_at')->label('Last successful sync')->since()->placeholder('Never')
                    ->tooltip(fn (EmailConnection $r) => $r->last_success_at?->timezone('Asia/Karachi')->format('d M Y H:i')),
            ])
            ->actions([
                Action::make('connect')->label('Sign in')->icon('heroicon-o-arrow-top-right-on-square')->color('primary')
                    ->visible(fn (EmailConnection $r) => $r->provider !== 'imap' && $r->status !== 'connected' && static::canCreate())
                    ->action(function (EmailConnection $record, $livewire): void {
                        try {
                            $livewire->redirect(app(MailOAuth::class)->authorizeUrl($record));
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('sync')->label('Sync now')->icon('heroicon-o-arrow-path')
                    ->visible(fn (EmailConnection $r) => in_array($r->status, ['connected', 'error'], true))
                    ->action(function (EmailConnection $record): void {
                        $run = app(BankEmailImport::class)->sync($record, 'manual');
                        $run->error === null
                            ? Notification::make()->success()->title("{$run->fetched} e-mail(s) read: {$run->imported} new, {$run->duplicates} duplicate, {$run->failed} could not be read.")->send()
                            : Notification::make()->danger()->title($run->error)->send();
                    }),
                ActionGroup::make([
                    Action::make('history')->label('Import history')->icon('heroicon-o-clock')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                        ->form(fn (EmailConnection $record) => [Placeholder::make('runs')->hiddenLabel()->content(self::history($record))]),
                    EditAction::make()->label('Bank account and frequency'),
                    Action::make('disconnect')->label('Disconnect')->icon('heroicon-o-no-symbol')->color('danger')
                        ->visible(fn (EmailConnection $r) => $r->status !== 'disconnected')
                        ->requiresConfirmation()->modalDescription('The saved sign-in is deleted; e-mails already read stay.')
                        ->action(function (EmailConnection $record): void {
                            $record->forceFill(['credentials' => null, 'oauth_state' => null, 'status' => 'disconnected'])->save();
                            app(AuditLogger::class)->record('bank_email.disconnected', $record, null, ['email' => $record->email]);
                            Notification::make()->success()->title('Disconnected.')->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('No mailbox connected')
            ->emptyStateDescription('Connect the mailbox your bank sends transaction alerts to; they appear under Bank e-mail transactions for checking.');
    }

    public static function history(EmailConnection $connection): HtmlString
    {
        $rows = EmailSyncRun::query()->where('email_connection_id', $connection->id)->latest('started_at')->limit(20)->get()
            ->map(fn (EmailSyncRun $r) => '<tr><td class="pr-3">'.e($r->started_at->timezone('Asia/Karachi')->format('d M H:i')).'</td><td class="pr-3">'.e($r->trigger).'</td>'
                .'<td class="pr-3 text-right">'.$r->fetched.'</td><td class="pr-3 text-right">'.$r->imported.'</td><td class="pr-3 text-right">'.$r->duplicates.'</td>'
                .'<td class="pr-3 text-right">'.$r->failed.'</td><td class="text-danger-600">'.e((string) $r->error).'</td></tr>')
            ->implode('');

        return new HtmlString($rows === '' ? 'Not read yet.'
            : '<table class="w-full text-sm"><thead><tr class="text-left"><th>When</th><th>How</th><th class="text-right">Read</th><th class="text-right">New</th><th class="text-right">Duplicate</th><th class="text-right">Failed</th><th>Error</th></tr></thead><tbody>'.$rows.'</tbody></table>');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailConnections::route('/'),
        ];
    }
}
