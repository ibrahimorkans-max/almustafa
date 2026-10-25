<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Auth\ActorContext;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Messaging\Gateways\FirebasePush;
use App\Domain\Messaging\Gateways\WhatsAppCloud;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Masters\Party;
use App\Models\PartyMessage;
use App\Models\PartyPushToken;
use App\Support\Values\PakistanIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Messages to parties: their login details, each money entry with them, a vehicle unloaded, a vehicle's
 * final weights. Each kind is on or off for the mill (Masters → Party messages), and each party can be
 * switched off. A message goes by WhatsApp or SMS through the configured gateway; without one it waits
 * in the list for the office to send by hand (a WhatsApp link with the text). Every message is kept with
 * how it went; one carrying a password is kept without its text.
 *
 * Messages are sent after the work is saved: a money entry that is rolled back sends nothing.
 */
final class PartyMessenger
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
    ) {}

    /**
     * Sends (or queues) a message to the party once the current work is saved.
     *
     * @param  bool  $redact  the text carries a password: keep the record without it
     */
    public function send(Party $party, string $event, string $text, ?Model $subject = null, bool $redact = false): void
    {
        DB::afterCommit(fn () => $this->deliver($party, $event, $text, $subject, $redact));
    }

    /** Sends now (not waiting for the work to be saved); returns the record, or null when this kind is switched off. */
    public function deliver(Party $party, string $event, string $text, ?Model $subject = null, bool $redact = false): ?PartyMessage
    {
        $entityId = $party->entity_id;
        // SaaS: a company whose access is stopped sends nothing (its parties' figures stay unseen too).
        if (! $this->settings->messageOn($event, $entityId) || app(CustomerAccess::class)->millBlocked($entityId)) {
            return null;
        }
        $channel = $this->settings->messageChannel($entityId);
        $to = PakistanIds::normalizeMobile($party->phone);
        $record = [
            'entity_id' => $entityId, 'party_id' => $party->id, 'event' => $event, 'channel' => $channel, 'recipient' => $to ?? (string) $party->phone,
            'body' => $redact ? null : $text, 'redacted' => $redact,
            'subject_type' => $subject !== null ? class_basename($subject) : null, 'subject_id' => $subject?->getKey(),
            'created_by' => $this->actor->accountId(),
        ];

        if ($party->messages_on === false) {   // not loaded yet (older party) counts as on
            return $this->store($record + ['status' => PartyMessage::SKIPPED, 'error' => 'Messages are switched off for this party.']);
        }
        // A notification on the party's phones and browsers too (not the login details: it has no device yet).
        if (! $redact) {
            $this->push($party, $event, $text, $record);
        }
        if ($to === null) {
            return $this->store($record + ['status' => PartyMessage::SKIPPED, 'error' => 'No mobile number for this party.']);
        }

        if ($channel === 'whatsapp' && WhatsAppCloud::configured()) {
            // The approved template carries the mill's name and the thank-you around {{1}}.
            $sent = app(WhatsAppCloud::class)->send($to, MessageText::forTemplate($text));

            return $this->store($record + ['status' => $sent['ok'] ? PartyMessage::SENT : PartyMessage::FAILED,
                'error' => $sent['error'], 'sent_at' => $sent['ok'] ? now() : null]);
        }
        $url = (string) config("ricemill.messaging.{$channel}_url");
        if ($url === '') {
            // No gateway: the office sends it by hand from the list.
            return $this->store($record + ['status' => PartyMessage::MANUAL]);
        }
        try {
            $response = Http::timeout(10)->withToken((string) config("ricemill.messaging.{$channel}_token"))
                ->acceptJson()->post($url, ['to' => $to, 'text' => $channel === 'sms' ? MessageText::plain($text) : $text, 'sender' => (string) config('ricemill.messaging.sender')]);
            $ok = $response->successful();

            return $this->store($record + ['status' => $ok ? PartyMessage::SENT : PartyMessage::FAILED,
                'error' => $ok ? null : 'Gateway answered HTTP '.$response->status(), 'sent_at' => $ok ? now() : null]);
        } catch (Throwable $e) {
            return $this->store($record + ['status' => PartyMessage::FAILED, 'error' => mb_substr($e->getMessage(), 0, 250)]);
        }
    }

    /**
     * The same message as a notification (Firebase) on each device the party turned notifications on for.
     *
     * @param  array<string, mixed>  $record
     */
    private function push(Party $party, string $event, string $text, array $record): void
    {
        if (! FirebasePush::configured() || ! $this->settings->pushOn($party->entity_id)) {
            return;
        }
        $devices = $this->tenant->runInEntity($party->entity_id, fn () => PartyPushToken::query()->where('party_id', $party->id)->count());
        if ($devices === 0) {
            return;
        }
        $title = $this->millName($party->entity_id).' — '.match ($event) {
            'money' => 'Account entry', 'unloaded' => 'Vehicle unloaded', 'weighed' => 'Vehicle weights', default => 'Message',
        };
        $result = $this->tenant->runInEntity($party->entity_id, fn () => app(FirebasePush::class)->sendToParty($party->id, $title,
            // The title names the mill; the notification shows the rest without bold marks.
            MessageText::plain(implode('
', array_slice(explode('
', $text), 1)))));
        $this->store(['channel' => 'push', 'recipient' => $result['sent'] + $result['failed'].' device(s)'] + $record + [
            'status' => $result['sent'] > 0 ? PartyMessage::SENT : PartyMessage::FAILED,
            'error' => $result['sent'] > 0 ? null : ($result['error'] ?? 'No device took it.'),
            'sent_at' => $result['sent'] > 0 ? now() : null,
        ]);
    }

    /** A WhatsApp link that opens the message ready to send from the office phone. */
    public static function whatsappLink(string $to, string $text): string
    {
        return 'https://wa.me/'.ltrim($to, '+').'?'.http_build_query(['text' => $text]);
    }

    /** The mill's name as it starts each message. */
    public function millName(?string $entityId): string
    {
        $entity = $entityId === null ? null : $this->tenant->runAsSystem(fn () => Entity::query()->find($entityId));

        return PdfRenderer::brand($entity)['name'];
    }

    /** @param  array<string, mixed>  $record */
    private function store(array $record): PartyMessage
    {
        // In the party's own mill, also when an Owner works across all firms.
        return $this->tenant->runInEntity((string) $record['entity_id'], function () use ($record): PartyMessage {
            $message = new PartyMessage(array_diff_key($record, ['entity_id' => true]));
            $message->entity_id = (string) $record['entity_id'];
            $message->save();

            return $message;
        });
    }
}
