<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Banking\EmailConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * The real mailboxes: Gmail (Gmail API), Outlook / Microsoft 365 (Microsoft Graph) and any other
 * mailbox over IMAP with an app password (ImapReader). Read-only in every case.
 */
final class ProviderMailbox implements Mailbox
{
    public function __construct(private readonly MailOAuth $oauth) {}

    public function fetch(EmailConnection $connection, CarbonImmutable $since, int $limit = 100): array
    {
        return match ($connection->provider) {
            'google' => $this->gmail($connection, $since, $limit),
            'microsoft' => $this->graph($connection, $since, $limit),
            'imap' => (new ImapReader((array) $connection->credentials))->fetch($since, $limit),
            default => throw new BusinessRuleViolation('Unknown e-mail provider.'),
        };
    }

    /** @return list<MailMessage> */
    private function gmail(EmailConnection $connection, CarbonImmutable $since, int $limit): array
    {
        $http = Http::withToken($this->oauth->accessToken($connection))->timeout(30)->baseUrl('https://gmail.googleapis.com/gmail/v1/users/me/');
        $list = $http->get('messages', ['q' => 'after:'.$since->getTimestamp(), 'maxResults' => min($limit, 100)]);
        if (! $list->successful()) {
            throw new BusinessRuleViolation('Gmail did not answer ('.$list->status().').');
        }
        $messages = [];
        foreach ((array) $list->json('messages', []) as $ref) {
            $m = $http->get('messages/'.$ref['id'], ['format' => 'full']);
            if (! $m->successful()) {
                continue;
            }
            $headers = collect((array) $m->json('payload.headers', []))->mapWithKeys(fn ($h) => [strtolower((string) $h['name']) => (string) $h['value']]);
            [$text, $html] = self::gmailBody((array) $m->json('payload', []));
            $messages[] = new MailMessage(
                (string) $ref['id'], (string) ($headers['from'] ?? ''), (string) ($headers['subject'] ?? ''),
                CarbonImmutable::createFromTimestampMs((int) $m->json('internalDate', 0)),
                $text !== '' ? MailMessage::textOf($text, false) : MailMessage::textOf($html, true),
            );
        }

        return $messages;
    }

    /**
     * The text and HTML parts of a Gmail message (base64url).
     *
     * @param  array<string, mixed>  $part
     * @return array{0: string, 1: string}
     */
    private static function gmailBody(array $part): array
    {
        $text = '';
        $html = '';
        $data = (string) ($part['body']['data'] ?? '');
        if ($data !== '') {
            $decoded = (string) base64_decode(strtr($data, '-_', '+/'));
            ($part['mimeType'] ?? '') === 'text/html' ? $html .= $decoded : $text .= $decoded;
        }
        foreach ((array) ($part['parts'] ?? []) as $child) {
            [$t, $h] = self::gmailBody((array) $child);
            $text .= $t;
            $html .= $h;
        }

        return [$text, $html];
    }

    /** @return list<MailMessage> */
    private function graph(EmailConnection $connection, CarbonImmutable $since, int $limit): array
    {
        $response = Http::withToken($this->oauth->accessToken($connection))->timeout(30)->get('https://graph.microsoft.com/v1.0/me/messages', [
            '$filter' => 'receivedDateTime ge '.$since->utc()->format('Y-m-d\TH:i:s\Z'),
            '$select' => 'id,from,subject,receivedDateTime,body',
            '$top' => min($limit, 100),
            '$orderby' => 'receivedDateTime desc',
        ]);
        if (! $response->successful()) {
            throw new BusinessRuleViolation('Outlook did not answer ('.$response->status().').');
        }

        return array_map(fn (array $m) => new MailMessage(
            (string) $m['id'],
            (string) ($m['from']['emailAddress']['address'] ?? ''),
            (string) ($m['subject'] ?? ''),
            isset($m['receivedDateTime']) ? CarbonImmutable::parse((string) $m['receivedDateTime']) : null,
            MailMessage::textOf((string) ($m['body']['content'] ?? ''), strtolower((string) ($m['body']['contentType'] ?? 'text')) === 'html'),
        ), array_values((array) $response->json('value', [])));
    }
}
