<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banking\EmailConnectionResource\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\BankEmailImport;
use App\Domain\Banking\MailOAuth;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\Banking\EmailConnectionResource;
use App\Models\Banking\EmailConnection;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class ListEmailConnections extends ListRecords
{
    protected static string $resource = EmailConnectionResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return BankEmailImport::enabledFor(app(TenantContext::class)->entityId())
            ? 'Transactions read from these mailboxes wait under Bank e-mail transactions until someone checks them. An e-mail alone never posts to the books.'
            : 'Bank e-mail import is switched off for your company. Ask the product owner to switch it on.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Connect a mailbox')->modalHeading('Connect a mailbox')
                ->form(EmailConnectionResource::fields(true))
                ->using(function (array $data): EmailConnection {
                    if (EmailConnection::query()->where('provider', $data['provider'])->where('email', mb_strtolower($data['email']))->exists()) {
                        throw ValidationException::withMessages(['mountedActionsData.0.email' => 'This mailbox is already connected.']);
                    }
                    $connection = new EmailConnection([
                        'provider' => $data['provider'], 'email' => mb_strtolower(trim($data['email'])),
                        'ledger_account_id' => $data['ledger_account_id'], 'sync_minutes' => (int) $data['sync_minutes'],
                    ]);
                    if ($data['provider'] === 'imap') {
                        $connection->credentials = ['host' => trim($data['imap_host']), 'port' => (int) $data['imap_port'],
                            'username' => trim($data['imap_username']), 'password' => (string) $data['imap_password']];
                        $connection->status = 'connected';
                    }
                    $connection->save();
                    app(AuditLogger::class)->record('bank_email.added', $connection, null, ['provider' => $connection->provider, 'email' => $connection->email]);

                    return $connection;
                })
                ->after(function (EmailConnection $record): void {
                    if ($record->provider === 'imap') {
                        // Read at once, so a wrong server or password shows now.
                        $run = app(BankEmailImport::class)->sync($record, 'manual');
                        $run->error === null
                            ? Notification::make()->success()->title("Connected: {$run->fetched} e-mail(s) read.")->send()
                            : Notification::make()->danger()->title('Saved, but reading failed: '.$run->error)->persistent()->send();

                        return;
                    }
                    try {
                        $this->redirect(app(MailOAuth::class)->authorizeUrl($record));
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                })
                ->successNotification(null),
        ];
    }
}
