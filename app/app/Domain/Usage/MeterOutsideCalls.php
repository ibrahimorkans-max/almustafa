<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;

/**
 * Finds every outside call by itself, so none is missed: each HTTP call the system makes (Laravel's HTTP client
 * events) and each e-mail it sends. Known hosts are named (provider, service, channel); calls to unknown hosts are
 * counted as "other integrations". Calls to this server itself are not counted.
 */
final class MeterOutsideCalls
{
    /** host suffix => [provider, category, channel] */
    private const HOSTS = [
        'openrouter.ai' => ['OpenRouter', 'ai', 'api'],
        'api.openai.com' => ['OpenAI', 'ai', 'api'],
        'api.anthropic.com' => ['Anthropic', 'ai', 'api'],
        'generativelanguage.googleapis.com' => ['Google Gemini', 'ai', 'api'],
        'api.groq.com' => ['Groq', 'ai', 'api'],
        'api.deepseek.com' => ['DeepSeek', 'ai', 'api'],
        'api.mistral.ai' => ['Mistral', 'ai', 'api'],
        'graph.facebook.com' => ['WhatsApp Cloud (Meta)', 'messaging', 'whatsapp'],
        'fcm.googleapis.com' => ['Firebase Cloud Messaging', 'push', 'push'],
        'oauth2.googleapis.com' => ['Google sign-in', 'other', 'api'],
        'gmail.googleapis.com' => ['Gmail API', 'mailbox', 'api'],
        'graph.microsoft.com' => ['Microsoft Graph', 'mailbox', 'api'],
        'login.microsoftonline.com' => ['Microsoft sign-in', 'other', 'api'],
        'vision.googleapis.com' => ['Google Vision OCR', 'ocr', 'api'],
        'maps.googleapis.com' => ['Google Maps', 'maps', 'api'],
        'api.ocr.space' => ['OCR.space', 'ocr', 'api'],
        'api.twilio.com' => ['Twilio', 'sms', 'sms'],
        'api.veevotech.com' => ['Veevo Tech SMS', 'sms', 'sms'],
    ];

    public function handle(ResponseReceived|ConnectionFailed|MessageSent $event): void
    {
        if ($event instanceof MessageSent) {
            $this->mail($event);

            return;
        }
        $request = $event->request;
        $known = $this->identify($request);
        if ($known === null) {
            return;
        }
        [$provider, $category, $channel] = $known;
        $body = $this->body($request);
        $response = $event instanceof ResponseReceived ? $event->response : null;
        $json = $response?->json();
        $json = is_array($json) ? $json : [];
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $u = [
            'channel' => $channel, 'category' => $category, 'provider' => $provider,
            'service' => $this->service($category, $path, $body),
            'endpoint' => preg_replace('/\/\d{6,}\//', '/{id}/', $path),
            'success' => $response !== null && $response->successful(),
            'response_status' => $response?->status(),
            'error' => $response === null ? 'Could not connect' : ($response->successful() ? null : mb_substr((string) $response->body(), 0, 200)),
            'meta' => ['request' => $body],
        ];
        if ($category === 'messaging') {
            // A WhatsApp message (not a media upload): its recipient, template and Meta's message id for delivery reports.
            if (! str_ends_with($path, '/messages')) {
                return;
            }
            $u['recipient'] = isset($body['to']) ? (string) $body['to'] : null;
            $u['template'] = $body['template']['name'] ?? null;
            $u['service'] = 'Message ('.($body['type'] ?? 'text').')';
            $u['provider_ref'] = $json['messages'][0]['id'] ?? null;
            $u['key'] = $u['provider_ref'] !== null ? 'wa:'.$u['provider_ref'] : null;
        }
        if ($category === 'ai') {
            $usage = (array) ($json['usage'] ?? $json['usageMetadata'] ?? []);
            $u['units'] = (int) ($usage['total_tokens'] ?? (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0)) ?: ($usage['totalTokenCount'] ?? 0)) ?: null;
            $u['service'] = 'Chat ('.mb_substr((string) ($body['model'] ?? $json['model'] ?? 'model'), 0, 60).')';
            $u['provider_ref'] = isset($json['id']) ? (string) $json['id'] : null;
            $u['key'] = $u['provider_ref'] !== null ? 'ai:'.$u['provider_ref'] : null;
            if (isset($usage['cost']) && is_numeric($usage['cost'])) {
                // OpenRouter reports what the call actually cost (US dollars).
                $u['actual_cost'] = number_format((float) $usage['cost'], 6, '.', '');
                $u['actual_currency'] = 'USD';
            }
        }
        if ($category === 'push') {
            $u['provider_ref'] = isset($json['name']) ? (string) $json['name'] : null;
        }
        UsageMeter::record($u);
    }

    private function mail(MessageSent $event): void
    {
        $message = $event->message;
        $to = array_map(fn ($a) => $a->getAddress(), $message->getTo());
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['array', 'log'], true) && ! app()->runningUnitTests()) {
            return;   // nothing was really sent
        }
        UsageMeter::record([
            'channel' => 'email', 'category' => 'email',
            'provider' => match ($mailer) {
                'ses' => 'Amazon SES', 'postmark' => 'Postmark', 'mailgun' => 'Mailgun', 'resend' => 'Resend', default => 'SMTP ('.(string) config('mail.mailers.smtp.host').')'
            },
            'service' => 'E-mail',
            'quantity' => max(1, count($to)),
            'recipient' => $to[0] ?? null,
            'success' => true,
            'provider_ref' => $message->getHeaders()->get('Message-ID')?->getBodyAsString(),
            'key' => 'mail:'.hash('sha256', ($message->getHeaders()->get('Message-ID')?->getBodyAsString() ?? '').implode(',', $to).$message->getSubject()),
            'meta' => ['subject' => mb_substr((string) $message->getSubject(), 0, 120), 'mailable' => $event->data['__laravel_mailable'] ?? null],
        ]);
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function identify(Request $request): ?array
    {
        $host = strtolower((string) parse_url($request->url(), PHP_URL_HOST));
        $own = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '' || $host === $own || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }
        foreach (self::HOSTS as $suffix => $known) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return $known;
            }
        }

        return [$host, 'other', 'api'];
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->data();

        return is_array($data) ? $data : [];
    }

    /** @param  array<string, mixed>  $body */
    private function service(string $category, string $path, array $body): string
    {
        return match ($category) {
            'push' => 'Push notification',
            'mailbox' => 'Mailbox read',
            default => mb_substr(trim((string) preg_replace('/[\/\d]+/', ' ', basename($path))) ?: 'Request', 0, 80),
        };
    }
}
