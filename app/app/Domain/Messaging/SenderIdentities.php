<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who a mill's messages come from. The connections (WhatsApp, SMS, e-mail) are the platform's, set up once by the
 * SaaS owner; each company or mill is given its sender identity — the provider-approved WhatsApp number with its
 * approved business name, the SMS sender, the e-mail address — and every message of that mill goes out under it.
 * A mill's own identity comes first, then its company's. With none (or one not approved) the mill sends nothing on
 * that channel and the screens say so: the platform's name is used for a mill only where the SaaS owner assigned
 * the platform's number to it on purpose.
 */
final class SenderIdentities
{
    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-mail'];

    public const STATUSES = ['approved' => 'Approved by the provider', 'pending' => 'Waiting for approval', 'inactive' => 'Not in use'];

    /** @var array<string, ?object> */
    private array $cache = [];

    /** The identity a mill's messages on a channel go out under (approved only), or null. */
    public static function for(string $channel, ?string $entityId): ?object
    {
        $self = app(self::class);

        return $self->cache[$channel.'|'.$entityId] ??= app(TenantContext::class)->runAsSystem(function () use ($channel, $entityId): ?object {
            if ($entityId === null) {
                return null;
            }
            $mill = DB::table('sender_identities')->where('channel', $channel)->where('entity_id', $entityId)->first();
            if ($mill === null) {
                $customerId = Entity::query()->whereKey($entityId)->value('customer_id');
                $mill = $customerId === null ? null : DB::table('sender_identities')->where('channel', $channel)->where('customer_id', $customerId)->first();
            }

            return $mill !== null && $mill->status === 'approved' ? $mill : null;
        });
    }

    /** The identity row as set (any status), for the screens. */
    public static function assigned(string $channel, ?string $entityId): ?object
    {
        return app(TenantContext::class)->runAsSystem(function () use ($channel, $entityId): ?object {
            if ($entityId === null) {
                return null;
            }

            return DB::table('sender_identities')->where('channel', $channel)->where('entity_id', $entityId)->first()
                ?? DB::table('sender_identities')->where('channel', $channel)
                    ->where('customer_id', Entity::query()->whereKey($entityId)->value('customer_id'))->first();
        });
    }

    /**
     * The company name a mill's messages carry: the name set for it here, else its letterhead's name (Branding), else
     * the mill's own name. Never the platform's.
     */
    public static function nameFor(?string $entityId): string
    {
        $row = self::assigned('whatsapp', $entityId);
        if ($row !== null && filled($row->display_name) && ! $row->platform_sender) {
            return (string) $row->display_name;
        }
        if ($row !== null && filled($row->display_name) && $row->platform_sender && $row->display_name !== config('ricemill.saas.product_name')) {
            return (string) $row->display_name;
        }
        $entity = $entityId === null ? null : app(TenantContext::class)->runAsSystem(fn () => Entity::query()->find($entityId));

        return (string) \App\Domain\Documents\PdfRenderer::brand($entity)['name'];
    }

    /** Why a mill cannot send on a channel, or null when it can. */
    public static function problem(string $channel, ?string $entityId): ?string
    {
        $row = self::assigned($channel, $entityId);

        return match (true) {
            $row === null && $channel === 'whatsapp' => null,   // the platform's one number, with the mill's name
            $row === null => 'No '.self::CHANNELS[$channel].' sender is assigned to this mill yet (the SaaS owner assigns one).',
            $row->status !== 'approved' => self::CHANNELS[$channel].' sender "'.$row->display_name.'" is '.($row->status === 'pending' ? 'waiting for the provider\'s approval' : 'not in use').'.',
            default => null,
        };
    }

    /**
     * Who an e-mail of a mill comes from: its approved e-mail sender (address and name), else the platform's sending
     * address under the mill's own name — never the platform's name.
     */
    public static function mailFrom(?string $entityId): Address
    {
        $row = self::for('email', $entityId);
        if ($row !== null) {
            return new Address((string) $row->sender, (string) $row->display_name);
        }
        $name = $entityId === null ? null : app(TenantContext::class)->runAsSystem(fn () => Entity::query()->whereKey($entityId)->value('name'));

        return new Address((string) config('mail.from.address'), (string) ($name ?? config('mail.from.name')));
    }

    /** "ALMUSTAFA RICE MILL (+92 300 …)" for the usage records. */
    public static function label(?object $row): ?string
    {
        return $row === null ? null : mb_substr($row->display_name.' ('.$row->sender.')', 0, 200);
    }

    /**
     * The SaaS owner sets (or, with null data, removes) a company's or one mill's sender identity on a channel.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function save(string $channel, ?string $customerId, ?string $entityId, ?array $data): void
    {
        $actor = app(ActorContext::class)->account() ?? auth()->user();
        if (! $actor instanceof LoginAccount || ! $actor->isPlatformAdmin()) {
            throw new BusinessRuleViolation('Only the SaaS owner assigns sender identities.');
        }
        if (! isset(self::CHANNELS[$channel]) || (($customerId === null) === ($entityId === null))) {
            throw new BusinessRuleViolation('Choose a channel and a company or one mill.');
        }
        app(TenantContext::class)->runAsSystem(function () use ($channel, $customerId, $entityId, $data, $actor): void {
            $where = ['channel' => $channel] + ($entityId !== null ? ['entity_id' => $entityId] : ['customer_id' => $customerId]);
            $before = DB::table('sender_identities')->where($where)->first();
            if ($data === null) {
                DB::table('sender_identities')->where($where)->delete();
            } else {
                if ($channel === 'whatsapp' && ! ($data['platform_sender'] ?? false) && blank($data['phone_number_id'] ?? null)) {
                    throw new BusinessRuleViolation('A WhatsApp sender needs its Phone number ID (WhatsApp Manager → Phone numbers).');
                }
                if ($channel === 'email' && filter_var((string) ($data['sender'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
                    throw new BusinessRuleViolation('Give the e-mail address messages are sent from.');
                }
                $values = array_intersect_key($data, array_flip(['provider', 'sender', 'display_name', 'phone_number_id', 'business_account_id',
                    'platform_sender', 'language', 'status', 'templates'])) + ['updated_by' => $actor->id, 'updated_at' => now()];
                if (array_key_exists('templates', $values)) {
                    $values['templates'] = $values['templates'] === null ? null : json_encode(array_values((array) $values['templates']));
                }
                if ($channel === 'whatsapp' && ($data['platform_sender'] ?? false)) {
                    // The platform's own number, under the platform's approved name: chosen on purpose for this mill.
                    $platform = Integrations::whatsapp(null);
                    $values['phone_number_id'] = $platform['phone_number_id'];
                    $values['sender'] = (string) ($platform['business_number'] ?? $platform['phone_number_id']);
                    $values['credential_ref'] = null;
                }
                if (filled($data['access_token'] ?? null)) {
                    // A number in another WhatsApp Business account: its own token, kept in the central encrypted store.
                    $ref = 'whatsapp-sender-'.Str::lower(Str::random(12));
                    Integrations::storeSenderToken($ref, (string) $data['access_token']);
                    $values['credential_ref'] = $ref;
                }
                $before === null
                    ? DB::table('sender_identities')->insert($where + $values + ['id' => (string) Str::uuid(), 'created_at' => now()])
                    : DB::table('sender_identities')->where($where)->update($values);
            }
            app(AuditLogger::class)->record('integration.sender_identity', null,
                $before === null ? null : array_diff_key((array) $before, ['credential_ref' => true]),
                $data === null ? null : array_diff_key($data, ['access_token' => true]), ['channel' => $channel, 'customer_id' => $customerId, 'entity_id' => $entityId]);
        });
        app(self::class)->cache = [];
    }
}
