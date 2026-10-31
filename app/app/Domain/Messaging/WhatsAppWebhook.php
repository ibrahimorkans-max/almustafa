<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\StatementShare;
use App\Models\Entity;
use App\Models\Masters\Party;
use App\Models\PartyMessage;
use App\Models\WhatsAppEvent;
use App\Support\Values\PakistanIds;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * The WhatsApp webhook (Meta → WhatsApp → Configuration → Webhook, field "messages"). Meta first checks the
 * address with the verify token (verify), then posts each event signed with the app's secret (signed,
 * receive):
 *  - a message's progress: sent, delivered, read or failed. It is matched to the party message or statement
 *    share by the id WhatsApp gave it, and the party message shows it (Masters → Party messages);
 *  - a message someone sent to the business number: kept with the party it came from (by mobile number).
 *
 * The address names the number it is for: 'platform' (the product's number) or a mill's id (its own number).
 */
final class WhatsAppWebhook
{
    /** A later state never goes back to an earlier one (read after delivered, not the other way). */
    private const ORDER = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    public function __construct(private readonly TenantContext $tenant) {}

    /** 'platform' or a mill that exists. */
    public function knownScope(string $scope): bool
    {
        return $scope === 'platform'
            || (Str::isUuid($scope) && $this->tenant->runAsSystem(fn () => Entity::query()->whereKey($scope)->exists()));
    }

    /** Meta's check of the address: the challenge back when the verify token matches. */
    public function verify(string $scope, ?string $mode, ?string $token, ?string $challenge): ?string
    {
        $saved = Integrations::whatsappWebhookToken($scope);

        return $mode === 'subscribe' && $saved !== null && is_string($token) && hash_equals($saved, $token) && is_string($challenge)
            ? $challenge : null;
    }

    /** The X-Hub-Signature-256 header is the body signed with the app secret; no secret saved: nothing is accepted. */
    public function signed(string $scope, string $body, ?string $signature): bool
    {
        $secret = Integrations::whatsappWebhookSecret($scope);

        return $secret !== null && is_string($signature)
            && hash_equals('sha256='.hash_hmac('sha256', $body, $secret), strtolower(trim($signature)));
    }

    /**
     * Keeps each event of one delivery from Meta.
     *
     * @param  array<string, mixed>  $payload
     * @return int events kept (repeats of an event already kept are not counted)
     */
    public function receive(string $scope, array $payload): int
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return 0;
        }
        $kept = 0;
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'messages' || ! is_array($change['value'] ?? null)) {
                    continue;
                }
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    $kept += is_array($status) ? $this->status($scope, $status) : 0;
                }
                foreach ((array) ($change['value']['messages'] ?? []) as $message) {
                    $kept += is_array($message) ? $this->message($scope, $message) : 0;
                }
            }
        }

        return $kept;
    }

    /** @param  array<string, mixed>  $s */
    private function status(string $scope, array $s): int
    {
        $id = (string) ($s['id'] ?? '');
        $state = (string) ($s['status'] ?? '');
        if ($id === '' || ! isset(self::ORDER[$state])) {
            return 0;
        }
        $at = $this->time($s['timestamp'] ?? null);

        return $this->tenant->runAsSystem(function () use ($scope, $s, $id, $state, $at): int {
            $message = PartyMessage::query()->where('provider_message_id', $id)->first();
            $share = $message === null ? StatementShare::query()->where('provider_message_id', $id)->first() : null;
            $entityId = $message->entity_id ?? $share->entity_id ?? ($scope !== 'platform' ? $scope : null);
            $error = collect((array) ($s['errors'] ?? []))->map(fn ($e) => is_array($e) ? trim(($e['code'] ?? '').' '.($e['title'] ?? $e['message'] ?? '')) : '')
                ->filter()->implode('; ');
            if (! $this->keep([
                'entity_id' => $entityId, 'scope' => $scope, 'kind' => WhatsAppEvent::STATUS, 'wa_message_id' => $id, 'status' => $state,
                'phone' => $this->phone($s['recipient_id'] ?? null), 'party_id' => $message->party_id ?? $share->party_id ?? null,
                'error' => $error !== '' ? mb_substr($error, 0, 300) : null, 'occurred_at' => $at,
            ])) {
                return 0;
            }
            foreach (PartyMessage::query()->where('provider_message_id', $id)->get() as $sent) {
                if (self::ORDER[$state] > (self::ORDER[(string) $sent->delivery_status] ?? 0)) {
                    $sent->forceFill(['delivery_status' => $state, 'delivery_at' => $at]
                        + ($state === 'failed' && $error !== '' ? ['error' => mb_substr('WhatsApp: '.$error, 0, 250)] : []))->save();
                }
            }

            return 1;
        });
    }

    /** @param  array<string, mixed>  $m */
    private function message(string $scope, array $m): int
    {
        $id = (string) ($m['id'] ?? '');
        if ($id === '') {
            return 0;
        }
        $type = (string) ($m['type'] ?? 'text');
        $body = match ($type) {
            'text' => (string) ($m['text']['body'] ?? ''),
            'button' => (string) ($m['button']['text'] ?? ''),
            'interactive' => (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''),
            default => (string) ($m[$type]['caption'] ?? ''),
        };
        $phone = $this->phone($m['from'] ?? null);

        return $this->tenant->runAsSystem(function () use ($scope, $id, $type, $body, $phone, $m): int {
            $party = $this->partyFor($scope, $phone);

            return $this->keep([
                'entity_id' => $party->entity_id ?? ($scope !== 'platform' ? $scope : null), 'scope' => $scope, 'kind' => WhatsAppEvent::MESSAGE,
                'wa_message_id' => $id, 'status' => 'received', 'phone' => $phone, 'party_id' => $party?->id, 'type' => mb_substr($type, 0, 20),
                'body' => $body !== '' ? mb_substr($body, 0, 4000) : null, 'occurred_at' => $this->time($m['timestamp'] ?? null),
            ]) ? 1 : 0;
        });
    }

    /** The party with this mobile number: in the mill whose number it is, or the only one anywhere for the product's number. */
    private function partyFor(string $scope, ?string $phone): ?Party
    {
        if ($phone === null) {
            return null;
        }
        $local = substr($phone, 3);   // "+923001234567" → "3001234567": every way it may be written ends in it
        $found = Party::query()->when($scope !== 'platform', fn ($q) => $q->where('entity_id', $scope))
            ->where('phone', 'like', '%'.substr($local, -7).'%')->limit(50)->get()
            ->filter(fn (Party $p) => PakistanIds::normalizeMobile($p->phone) === $phone)->values();

        return $scope === 'platform' && $found->pluck('entity_id')->unique()->count() !== 1 ? null : $found->first();
    }

    /** @param  array<string, mixed>  $row */
    private function keep(array $row): bool
    {
        $again = WhatsAppEvent::query()->where('wa_message_id', $row['wa_message_id'])->where('kind', $row['kind'])->where('status', $row['status'])->exists();
        if ($again) {
            return false;   // Meta sent this event again
        }
        try {
            WhatsAppEvent::query()->create($row + ['created_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return false;   // Meta sent this event again
        }

        return true;
    }

    private function phone(mixed $value): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        return PakistanIds::normalizeMobile((string) $value) ?? '+'.ltrim(preg_replace('/\D/', '', (string) $value) ?? '', '+');
    }

    private function time(mixed $unix): CarbonImmutable
    {
        return is_numeric($unix) ? CarbonImmutable::createFromTimestamp((int) $unix) : CarbonImmutable::now();
    }
}
