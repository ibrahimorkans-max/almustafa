<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Messaging\Gateways\WhatsAppCloud;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Masters\Party;
use App\Models\PartyMessage;
use App\Support\Values\PakistanIds;

/**
 * A document (slip, invoice, bill, voucher, gate pass, statement) sent as a file on the mill's connected WhatsApp,
 * with a short message made from the mill's own template (Administration → Integrations → WhatsApp file
 * message). The words in braces are filled in: {party} {document} {number} {date} {mill}. The message to a
 * party is kept under Party messages and in Chats, where WhatsApp's delivered / read receipts show.
 */
final class WhatsAppFiles
{
    public const DEFAULT_MESSAGE = 'Dear {party}, please find your {document} {number} attached. {mill}';

    /** What each word in braces becomes. */
    public const PLACEHOLDERS = [
        '{party}' => 'the party\'s name', '{document}' => 'the kind of document (e.g. weighment slip)',
        '{number}' => 'its number', '{date}' => 'today\'s date', '{mill}' => 'the mill\'s name',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    public static function ready(?string $entityId = null): bool
    {
        return WhatsAppCloud::configured($entityId);
    }

    /** The mill's template, or the standard one. */
    public function template(?string $entityId): string
    {
        $saved = trim((string) $this->settings->get(SettingsService::WHATSAPP_FILE_MESSAGE, $entityId));

        return $saved !== '' ? $saved : self::DEFAULT_MESSAGE;
    }

    public function saveTemplate(string $entityId, string $text): void
    {
        $text = trim($text);
        if (mb_strlen($text) > 700) {
            throw new BusinessRuleViolation('Keep the message short: 700 letters at most.');
        }
        $this->settings->put(SettingsService::WHATSAPP_FILE_MESSAGE, $text === self::DEFAULT_MESSAGE ? '' : $text, $entityId);
    }

    /** The message for one document, from the mill's template. */
    public function message(?string $entityId, ?string $partyName, string $document, string $number): string
    {
        $mill = $entityId !== null ? PdfRenderer::brand($this->tenant->runAsSystem(fn () => Entity::query()->find($entityId)))['name'] : '';

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', strtr($this->template($entityId), [
            '{party}' => (string) $partyName, '{document}' => $document, '{number}' => $number,
            '{date}' => now('Asia/Karachi')->format('d M Y'), '{mill}' => $mill,
        ])));
    }

    /**
     * Sends the file; a party's message is kept (Party messages, Chats).
     *
     * @return array{ok: bool, error: string|null}
     */
    public function send(?Party $party, string $phone, string $bytes, string $fileName, string $text, string $document): array
    {
        $entityId = $party->entity_id ?? $this->tenant->entityId();
        if (! self::ready($entityId)) {
            throw new BusinessRuleViolation('Connect WhatsApp first (Administration → Integrations).');
        }
        $to = PakistanIds::normalizeMobile($phone) ?? throw new BusinessRuleViolation('Give a mobile number such as 0300 1234567.');
        if (trim($text) === '') {
            throw new BusinessRuleViolation('Write the message to go with the file.');
        }
        $sent = (new WhatsAppCloud($entityId))->sendDocument($to, $bytes, $fileName, $text);
        if ($party !== null) {
            $this->tenant->runInEntity($party->entity_id, function () use ($party, $to, $text, $fileName, $sent): void {
                $m = new PartyMessage(['party_id' => $party->id, 'event' => 'document', 'channel' => 'whatsapp', 'recipient' => $to,
                    'body' => trim($text)."\n📎 ".$fileName, 'redacted' => false, 'status' => $sent['ok'] ? PartyMessage::SENT : PartyMessage::FAILED,
                    'error' => $sent['error'], 'sent_at' => $sent['ok'] ? now() : null, 'provider_message_id' => $sent['id'],
                    'created_by' => $this->actor->accountId()]);
                $m->entity_id = $party->entity_id;
                $m->save();
            });
        }
        $this->audit->record('whatsapp.file_sent', $party, null, null, ['document' => $document, 'file' => $fileName, 'ok' => $sent['ok']]);

        return ['ok' => $sent['ok'], 'error' => $sent['error']];
    }
}
