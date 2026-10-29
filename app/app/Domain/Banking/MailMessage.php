<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use Carbon\CarbonImmutable;

/** One e-mail as read from a mailbox: only what is needed to read a bank notification (no attachments). */
final readonly class MailMessage
{
    public function __construct(
        public string $id,
        public string $from,
        public string $subject,
        public ?CarbonImmutable $receivedAt,
        public string $text,
    ) {}

    /** Plain text of an HTML body: tags removed, entities decoded, white space collapsed. */
    public static function textOf(string $body, bool $html): string
    {
        if ($html) {
            $body = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body);
            $body = (string) preg_replace('#<(br|/p|/tr|/div|/li)\b[^>]*>#i', "\n", $body);
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return trim((string) preg_replace("/[ \t\x{00A0}]+/u", ' ', (string) preg_replace("/\r\n?/", "\n", $body)));
    }
}
