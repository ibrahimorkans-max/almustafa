<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Mail\DocumentMail;
use App\Models\Documents\DocumentShare;
use App\Models\Documents\GeneratedDocument;
use App\Models\Hr\Employee;
use App\Support\Values\PakistanIds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends an issued document: by e-mail with the PDF attached, or as a link by WhatsApp, SMS or copied
 * by hand. Links are random tokens (only their hash is kept), expire, can be limited to a number of
 * openings and revoked at any time; a payslip link can open a copy protected with the employee's
 * CNIC. Only the current revision can be shared, and every share, opening and refusal is logged.
 *
 * WhatsApp and SMS go through a provider only when one is configured; until then WhatsApp gives a
 * wa.me link to send from the HR phone, and SMS is written to the log for sending by hand.
 */
final class DocumentSharing
{
    public const MAX_HOURS = 24 * 30;

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly DocumentService $documents,
    ) {}

    /**
     * @return array{share: DocumentShare, url: string, send_url: string|null, message: string}
     */
    public function share(GeneratedDocument $doc, string $channel, ?string $recipient = null, int $hours = 72, ?int $maxOpens = null, bool $protect = false): array
    {
        if (! ($this->actor->account()?->hasPermission('hr.documents.share') ?? false)) {
            throw new BusinessRuleViolation('Sharing documents needs the permission hr.documents.share.');
        }
        if (! array_key_exists($channel, DocumentShare::CHANNELS)) {
            throw new BusinessRuleViolation('Unknown way of sending.');
        }
        if (! $doc->isCurrent()) {
            throw new BusinessRuleViolation('This document was replaced by a correction; share the latest revision.');
        }
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw new BusinessRuleViolation('A link may stay valid from 1 hour to 30 days.');
        }
        if ($maxOpens !== null && ($maxOpens < 1 || $maxOpens > 100)) {
            throw new BusinessRuleViolation('Allow 1 to 100 openings, or leave it open.');
        }
        if ($protect && ($doc->employee_id === null || Employee::query()->find($doc->employee_id)?->cnic === null)) {
            throw new BusinessRuleViolation('Only a document of an employee with a CNIC on file can be protected with it.');
        }
        $recipient = $this->recipient($channel, $recipient);

        return DB::transaction(function () use ($doc, $channel, $recipient, $hours, $maxOpens, $protect): array {
            $token = Str::random(48);
            $share = new DocumentShare;
            $share->fill(['generated_document_id' => $doc->id, 'channel' => $channel, 'recipient' => $recipient, 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours($hours), 'max_opens' => $maxOpens]);
            $share->forceFill(['protected' => $protect])->save();
            $url = route('documents.shared', ['token' => $token]);
            $title = DocumentTypes::get($doc->doc_type)->label().' '.$doc->displayNumber();
            $mill = (string) config('ricemill.brand.name');
            $until = $share->expires_at->timezone('Asia/Karachi')->format('d M Y H:i');
            $message = "{$mill}: {$title}. Open until {$until} PKT: {$url}".($protect ? ' (password: last 5 digits of your CNIC)' : '');
            $sendUrl = null;

            match ($channel) {
                'email' => Mail::to((string) $recipient)->send(new DocumentMail($doc, $this->documents->original($doc)['bytes'], $title, $message)),
                'whatsapp' => $sendUrl = 'https://wa.me/'.ltrim((string) $recipient, '+').'?'.http_build_query(['text' => $message]),
                // No SMS provider yet: the sender sees the text on screen and sends it. The log never holds
                // the link (it opens the document) or the full number.
                'sms' => Log::channel((string) config('ricemill.security.sms_log_channel', 'stack'))->info('SMS prepared for sending by hand', [
                    'to' => mb_substr((string) $recipient, 0, 4).'*****'.mb_substr((string) $recipient, -3), 'document' => $title, 'share' => $share->id]),
                default => null,
            };
            $this->documents->log($doc, 'share', $share, ['channel' => $channel, 'hours' => $hours, 'max_opens' => $maxOpens, 'protected' => $protect]);
            $this->audit->record('documents.shared', $doc, null, null, ['channel' => $channel, 'share' => $share->id]);

            return ['share' => $share, 'url' => $url, 'send_url' => $sendUrl, 'message' => $message];
        });
    }

    public function revoke(DocumentShare $share): void
    {
        if (! ($this->actor->account()?->hasPermission('hr.documents.share') ?? false)) {
            throw new BusinessRuleViolation('Revoking links needs the permission hr.documents.share.');
        }
        if ($share->revoked_at !== null) {
            return;
        }
        $share->forceFill(['revoked_at' => now(), 'revoked_by' => $this->actor->accountId()])->save();
        $this->documents->log($share->document, 'revoke', $share);
    }

    /**
     * Opens a shared link (public, no sign-in). Returns the document, or null when the link is unknown,
     * expired, used up or revoked (that refusal is logged against the document).
     *
     * @return array{bytes: string, mime: string, name: string}|null
     */
    public function open(string $token): ?array
    {
        $share = $this->tenant->runAsSystem(fn () => DocumentShare::query()->where('token_hash', hash('sha256', $token))->first());
        if ($share === null) {
            return null;
        }

        return $this->tenant->runInEntity($share->entity_id, function () use ($share): ?array {
            return DB::transaction(function () use ($share): ?array {
                /** @var DocumentShare $share */
                $share = DocumentShare::query()->lockForUpdate()->findOrFail($share->id);
                $doc = $share->document;
                if (! $share->isUsable() || ! $doc->isCurrent()) {
                    $this->documents->log($doc, 'refused_link', $share, ['why' => $share->revoked_at !== null ? 'revoked' : (! $doc->isCurrent() ? 'replaced' : 'expired')]);

                    return null;
                }
                $out = $this->documents->original($doc, $share);
                $share->forceFill(['open_count' => $share->open_count + 1])->save();
                $this->documents->log($doc, 'open_link', $share);
                if ($share->protected) {
                    $cnic = (string) preg_replace('/\D/', '', (string) Employee::query()->find($doc->employee_id)?->cnic);
                    $out['bytes'] = app(DocumentRenderer::class)->protect($out['bytes'], substr($cnic, -5));
                }

                return $out;
            });
        });
    }

    private function recipient(string $channel, ?string $recipient): ?string
    {
        $recipient = $recipient !== null ? trim($recipient) : null;

        return match ($channel) {
            'email' => filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false ? (string) $recipient : throw new BusinessRuleViolation('Give a valid e-mail address.'),
            'whatsapp', 'sms' => PakistanIds::normalizeMobile((string) $recipient) ?? throw new BusinessRuleViolation('Give a Pakistani mobile number, e.g. 0300-1234567.'),
            default => $recipient === '' ? null : $recipient,
        };
    }
}
