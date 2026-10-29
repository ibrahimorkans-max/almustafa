<?php

declare(strict_types=1);

namespace App\Filament\Pages\Platform;

use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\MailOAuth;
use App\Domain\Tenancy\TenantContext;
use App\Models\Banking\EmailConnection;
use App\Models\Banking\EmailSyncRun;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Platform → Email integration (product owner): which providers this server can sign in with (set in
 * .env), the sync policy, bank e-mail import switched on or off per customer, and the health of every
 * connected mailbox with its recent errors. Templates are under Bank e-mail templates.
 */
class EmailIntegrationPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Email integration';

    protected static ?string $title = 'Email integration';

    protected static ?string $slug = 'platform/email-integration';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.platform.email-integration';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    /** @return list<array{label: string, ready: bool, how: string}> */
    public function getProviders(): array
    {
        return [
            ['label' => 'Gmail / Google Workspace (OAuth, read-only)', 'ready' => MailOAuth::configured('google'),
                'how' => 'BANK_EMAIL_GOOGLE_CLIENT_ID and BANK_EMAIL_GOOGLE_CLIENT_SECRET in .env; redirect URI '.MailOAuth::redirectUri()],
            ['label' => 'Outlook / Microsoft 365 (OAuth, Mail.Read)', 'ready' => MailOAuth::configured('microsoft'),
                'how' => 'BANK_EMAIL_MICROSOFT_CLIENT_ID, BANK_EMAIL_MICROSOFT_CLIENT_SECRET (and _TENANT) in .env; redirect URI '.MailOAuth::redirectUri()],
            ['label' => 'Other mailboxes (IMAP with an app password)', 'ready' => (bool) config('ricemill.bank_email.imap'), 'how' => 'BANK_EMAIL_IMAP=true'],
        ];
    }

    /** @return array{connections: array<string, int>, errors: list<array{when: string, mill: string, email: string, error: string}>, minimum: int} */
    public function getHealth(): array
    {
        return app(TenantContext::class)->runAsSystem(function (): array {
            $mills = Entity::query()->pluck('name', 'id');
            $emails = EmailConnection::query()->pluck('email', 'id');

            return [
                'connections' => EmailConnection::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
                'errors' => EmailSyncRun::query()->whereNotNull('error')->latest('started_at')->limit(15)->get()
                    ->map(fn (EmailSyncRun $r) => ['when' => $r->started_at->timezone('Asia/Karachi')->format('d M H:i'), 'mill' => (string) ($mills[$r->entity_id] ?? '?'),
                        'email' => (string) ($emails[$r->email_connection_id] ?? '?'), 'error' => (string) $r->error])->all(),
                'minimum' => (int) config('ricemill.bank_email.min_sync_minutes', 15),
            ];
        });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Customer::query())
            ->heading('Bank e-mail import per customer')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->fontFamily('mono'),
                TextColumn::make('mailboxes')->label('Mailboxes')->state(fn (Customer $record) => app(TenantContext::class)->runAsSystem(
                    fn () => EmailConnection::query()->whereIn('entity_id', Entity::query()->where('customer_id', $record->id)->select('id'))->count())),
                ToggleColumn::make('bank_email_enabled')->label('Allowed')
                    ->afterStateUpdated(fn (Customer $record, bool $state) => app(AuditLogger::class)->record('platform.bank_email.'.($state ? 'enabled' : 'disabled'), $record)),
            ])
            ->defaultSort('name');
    }
}
