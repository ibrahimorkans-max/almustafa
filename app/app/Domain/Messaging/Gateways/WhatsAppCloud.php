<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Gateways;

use App\Domain\Messaging\Integrations;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp Business Cloud API (Meta Graph API). A message the mill starts goes as the approved template
 * (default "mill_update", body e.g. "Al Mustafa Rice Mills: {{1}} Thank you." — Meta refuses a template that
 * is only a variable or starts or ends with one) with our text as its one variable; the template is made once
 * in WhatsApp Manager and approved by Meta. Variables may not hold new lines, tabs or more than four spaces in
 * a row, so the text is flattened.
 *
 * Whose number: the mill's own, connected under Administration → Integrations; else the product's
 * (Platform → Integrations); else the server's .env settings (Integrations::whatsapp).
 */
final class WhatsAppCloud
{
    /** @param  string|null  $entityId  the mill sending; null: the mill being worked in */
    public function __construct(private readonly ?string $entityId = null) {}

    public static function configured(?string $entityId = null): bool
    {
        $c = Integrations::whatsapp($entityId ?? app(TenantContext::class)->entityId());

        return $c['phone_number_id'] !== '' && $c['access_token'] !== '';
    }

    /** @return array{ok: bool, error: string|null, id: string|null} */
    public function send(string $to, string $text): array
    {
        $c = Integrations::whatsapp($this->entityId ?? app(TenantContext::class)->entityId());
        $url = 'https://graph.facebook.com/'.$c['api_version'].'/'.$c['phone_number_id'].'/messages';
        $body = mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 1000);
        try {
            $response = Http::timeout(15)->withToken((string) $c['access_token'])->acceptJson()->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($to, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $c['template'],
                    'language' => ['code' => $c['language']],
                    'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $body]]]],
                ],
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'WhatsApp not reached: '.mb_substr($e->getMessage(), 0, 200), 'id' => null];
        }
        if ($response->successful()) {
            return ['ok' => true, 'error' => null, 'id' => $response->json('messages.0.id')];
        }

        return ['ok' => false, 'error' => 'WhatsApp: '.mb_substr((string) ($response->json('error.message') ?? 'HTTP '.$response->status()), 0, 220), 'id' => null];
    }
}
