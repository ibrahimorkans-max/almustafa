<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\MailOAuth;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Banking\EmailConnectionResource;
use App\Models\Banking\EmailConnection;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /admin/bank-email/callback: Google or Microsoft sends the person back here after signing in the
 * mailbox. The one-time state finds the connection, only inside the mill the person is working in, and
 * only for someone who may manage mailboxes; the code is exchanged for tokens kept encrypted.
 */
final class BankEmailCallbackController
{
    public function __invoke(Request $request, MailOAuth $oauth): RedirectResponse
    {
        abort_unless(EmailConnectionResource::canCreate(), 403);
        $state = (string) $request->query('state', '');
        $connection = $state === '' ? null : EmailConnection::query()->where('oauth_state', $state)->first();
        abort_if($connection === null || strlen($state) < 40, 404);

        if ($request->filled('error') || ! $request->filled('code')) {
            $connection->forceFill(['oauth_state' => null, 'status' => 'error', 'last_error' => 'Sign-in was cancelled or refused.'])->save();
            Notification::make()->title('The mailbox was not connected: sign-in was cancelled or refused.')->danger()->send();

            return redirect(EmailConnectionResource::getUrl());
        }
        try {
            $oauth->complete($connection, (string) $request->query('code'));
            app(AuditLogger::class)->record('bank_email.connected', $connection, null, ['provider' => $connection->provider, 'email' => $connection->email]);
            Notification::make()->title("{$connection->email} is connected. Its bank e-mails are read every {$connection->sync_minutes} minutes.")->success()->send();
        } catch (BusinessRuleViolation $e) {
            $connection->forceFill(['oauth_state' => null, 'status' => 'error', 'last_error' => $e->getMessage()])->save();
            Notification::make()->title($e->getMessage())->danger()->send();
        }

        return redirect(EmailConnectionResource::getUrl());
    }
}
