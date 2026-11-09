<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Domain\Auth\ActorContext;
use App\Domain\Messaging\SenderIdentities;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Usage\ServiceUsage;
use App\Models\Usage\ServiceUsageUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records every use of an outside service as its own ServiceUsage row: who (or which automatic process), which
 * company and mill, the module and what for, exactly when (UTC), how many, whether it worked, and what it cost at
 * the price that applied (Pricing). Calls are found by themselves (MeterOutsideCalls: every HTTP call and every
 * e-mail); UsageContext adds a better reason or a transaction where the caller knows it.
 *
 * Metering never breaks the work it measures: a failure to record is logged, not thrown.
 */
final class UsageMeter
{
    /**
     * @param  array{channel: string, category: string, provider: string, service: string, endpoint?: ?string, success: bool,
     *     quantity?: int, units?: ?int, response_status?: ?int, error?: ?string, recipient?: ?string, template?: ?string,
     *     provider_ref?: ?string, actual_cost?: ?string, actual_currency?: ?string, meta?: array<string, mixed>, key?: ?string,
     *     module?: ?string, action?: ?string, description?: ?string}  $u
     */
    public static function record(array $u): ?ServiceUsage
    {
        try {
            $ctx = UsageContext::current();
            [$module, $action] = $u['module'] ?? null ? [$u['module'], $u['action'] ?? $u['service']] : self::origin();
            $module = $ctx['module'] ?? $module;
            $action = $ctx['action'] ?? $action;
            $tenant = app(TenantContext::class);
            $entityId = $tenant->entityId();
            $actor = app(ActorContext::class)->account();
            $quantity = max(1, (int) ($u['quantity'] ?? 1));
            $at = now();
            // The same call counted once: the caller's own key (a message id, a job), else what was sent within the minute.
            $key = $ctx['key'] ?? $u['key'] ?? hash('sha256', implode('|', [$u['channel'], $u['provider'], $u['endpoint'] ?? '', $u['recipient'] ?? '',
                json_encode($u['meta']['request'] ?? null), $u['success'] ? 1 : 0, $at->format('YmdHi'), $entityId]));
            $key = mb_substr((string) $key, 0, 80);

            return $tenant->runAsSystem(function () use ($u, $ctx, $module, $action, $entityId, $actor, $quantity, $at, $key): ?ServiceUsage {
                if (ServiceUsage::query()->where('idempotency_key', $key)->exists()) {
                    return null;
                }
                $customerId = $entityId !== null ? Entity::query()->whereKey($entityId)->value('customer_id') : null;
                $cost = Pricing::cost($customerId, $u['channel'], $u['provider'], $u['service'], $quantity, $at);

                // Two calls at the same moment may take the same next reference: the second tries the one after.
                return retry(3, fn () => DB::transaction(fn () => ServiceUsage::query()->create([
                    'reference' => self::nextReference($at->year),
                    'idempotency_key' => $key,
                    'customer_id' => $customerId, 'entity_id' => $entityId,
                    'channel' => $u['channel'], 'category' => $u['category'], 'provider' => mb_substr($u['provider'], 0, 60),
                    'service' => mb_substr($u['service'], 0, 80), 'endpoint' => isset($u['endpoint']) ? mb_substr((string) $u['endpoint'], 0, 160) : null,
                    'module' => mb_substr((string) $module, 0, 60), 'action' => mb_substr((string) $action, 0, 120),
                    'description' => mb_substr((string) ($ctx['description'] ?? $u['description'] ?? self::describe($u, (string) $action)), 0, 300),
                    'login_account_id' => $actor?->id,
                    'process' => $actor === null ? self::process() : null,
                    'recipient_masked' => isset($u['recipient']) ? self::mask((string) $u['recipient']) : null,
                    'template' => $u['template'] ?? null,
                    // The sender the message went out under (the mill's own identity).
                    'sender_identity' => in_array($u['channel'], ['whatsapp', 'sms', 'email'], true) ? SenderIdentities::label(SenderIdentities::for($u['channel'], $entityId)) : null,
                    'related_type' => $ctx['related_type'] ?? null, 'related_id' => $ctx['related_id'] ?? null,
                    'provider_ref' => isset($u['provider_ref']) ? mb_substr((string) $u['provider_ref'], 0, 120) : null,
                    'quantity' => $quantity, 'units' => $u['units'] ?? null,
                    'success' => $u['success'], 'response_status' => $u['response_status'] ?? null,
                    'error' => isset($u['error']) ? mb_substr((string) $u['error'], 0, 200) : null,
                    'price_id' => $cost['price_id'], 'unit_price' => $cost['unit_price'],
                    'estimated_cost' => $u['success'] ? $cost['estimated'] : '0', 'charge' => $u['success'] ? $cost['charge'] : '0',
                    'currency' => $cost['currency'],
                    'actual_cost' => $u['actual_cost'] ?? null, 'actual_currency' => $u['actual_currency'] ?? null,
                    'billing_status' => $u['success'] ? $cost['billing_status'] : 'free',
                    'meta' => array_diff_key($u['meta'] ?? [], ['request' => true]) ?: null,
                    'occurred_at' => $at,
                ])), 50);
            });
        } catch (Throwable $e) {
            Log::warning('Service usage could not be recorded: '.$e->getMessage());

            return null;
        }
    }

    /** A delivery report (WhatsApp sent / delivered / read / failed) or a provider's cost, against the usage it belongs to. */
    public static function update(string $providerRef, string $kind, ?string $status = null, ?string $amount = null, ?string $currency = null, ?string $note = null): void
    {
        try {
            app(TenantContext::class)->runAsSystem(function () use ($providerRef, $kind, $status, $amount, $currency, $note): void {
                $usage = ServiceUsage::query()->where('provider_ref', $providerRef)->first();
                if ($usage === null || ServiceUsageUpdate::query()->where('service_usage_id', $usage->id)->where('kind', $kind)->where('status', $status)->exists()) {
                    return;
                }
                ServiceUsageUpdate::query()->create(['service_usage_id' => $usage->id, 'entity_id' => $usage->entity_id, 'kind' => $kind, 'status' => $status,
                    'amount' => $amount, 'currency' => $currency, 'note' => $note, 'created_by' => app(ActorContext::class)->account()?->id, 'occurred_at' => now()]);
            });
        } catch (Throwable $e) {
            Log::warning('Service usage update could not be recorded: '.$e->getMessage());
        }
    }

    /** "+92 300 *** **67", "a***@gmail.com": enough to recognise, not enough to use. */
    public static function mask(string $to): string
    {
        $to = trim($to);
        if (str_contains($to, '@')) {
            [$name, $domain] = explode('@', $to, 2);

            return mb_substr($name, 0, 1).'***@'.$domain;
        }
        $digits = (string) preg_replace('/\D/', '', $to);

        return strlen($digits) <= 4 ? '****' : substr($digits, 0, 4).str_repeat('*', max(0, strlen($digits) - 6)).substr($digits, -2);
    }

    /**
     * The module and the action that called the outside service, from the code that did it: the outermost part of
     * the system (the module) and the nearest business step (the action).
     *
     * @return array{0: string, 1: string}
     */
    private static function origin(): array
    {
        $skip = '/^App\\\\(Domain\\\\Usage|Listeners|Domain\\\\Messaging\\\\Gateways|Domain\\\\Assistant\\\\AiGateway|Domain\\\\Messaging\\\\PartyMessenger|Providers)/';
        $frames = array_values(array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60),
            fn (array $f) => isset($f['class']) && str_starts_with($f['class'], 'App\\') && preg_match($skip, $f['class']) !== 1 && ! str_contains($f['function'], '{closure}')));
        if ($frames === []) {
            return ['System', 'Automatic'];
        }
        $outer = end($frames)['class'];
        $inner = $frames[0];
        $module = match (true) {
            str_contains($outer, '\\Gate\\') || str_contains($outer, 'Weigh') || str_contains($outer, 'Lab') => 'Weighbridge & lab',
            str_contains($outer, 'Assistant') => 'AI assistant',
            str_contains($outer, 'Roznamcha') => 'Roznamcha',
            str_contains($outer, 'Banking') => 'Bank e-mail',
            str_contains($outer, 'Sales') => 'Sales',
            str_contains($outer, 'Procurement') || str_contains($outer, 'Sauda') => 'Procurement',
            str_contains($outer, 'Accounts') || str_contains($outer, 'Statement') => 'Accounts',
            str_contains($outer, 'Hr\\') || str_contains($outer, 'Payroll') => 'HR & payroll',
            str_contains($outer, 'Messaging') || str_contains($outer, 'WhatsApp') || str_contains($outer, 'Party') => 'Party messages',
            str_contains($outer, 'Platform') || str_contains($outer, 'Saas') => 'Platform',
            str_contains($outer, 'Auth') || str_contains($outer, 'Security') => 'Security',
            default => Str::headline(class_basename($outer)),
        };

        return [$module, Str::headline(class_basename($inner['class'])).' → '.Str::headline($inner['function'])];
    }

    /** The automatic process that used the service, when no person did. */
    private static function process(): string
    {
        if (app()->runningInConsole()) {
            $argv = (array) ($_SERVER['argv'] ?? []);

            return mb_substr('command: '.implode(' ', array_slice($argv, 1, 3)), 0, 120);
        }

        return mb_substr('request: '.(request()?->path() ?? 'system'), 0, 120);
    }

    /** @param  array<string, mixed>  $u */
    private static function describe(array $u, string $action): string
    {
        $what = match ($u['channel']) {
            'whatsapp' => 'WhatsApp message'.(isset($u['template']) ? ' (template '.$u['template'].')' : ''),
            'email' => 'E-mail',
            'sms' => 'SMS',
            'push' => 'Push notification',
            default => ServiceUsage::CATEGORIES[$u['category']] ?? 'API',
        };

        return $what.' via '.$u['provider'].' for '.$action.($u['success'] ? '' : ' — failed');
    }

    private static function nextReference(int $year): string
    {
        $last = ServiceUsage::query()->where('reference', 'like', "U-{$year}-%")->orderByDesc('reference')->value('reference');
        $n = $last === null ? 1 : ((int) substr((string) $last, 7)) + 1;

        return sprintf('U-%d-%06d', $year, $n);
    }
}
