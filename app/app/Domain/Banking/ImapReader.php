<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Shared\BusinessRuleViolation;
use Carbon\CarbonImmutable;

/**
 * A small read-only IMAP client over TLS (PHP's imap extension is not needed): LOGIN, EXAMINE INBOX
 * (read-only, so nothing is marked as read), SEARCH SINCE and FETCH of the header and text of each new
 * e-mail. For mailboxes without OAuth, with an app password.
 *
 * @phpstan-type Settings array{host?: string, port?: int, username?: string, password?: string}
 */
final class ImapReader
{
    /** @var resource|null */
    private $socket = null;

    private int $tag = 0;

    /** @param  array<string, mixed>  $settings */
    public function __construct(private readonly array $settings) {}

    /** @return list<MailMessage> */
    public function fetch(CarbonImmutable $since, int $limit): array
    {
        $host = (string) ($this->settings['host'] ?? '');
        if ($host === '' || ! filled($this->settings['username'] ?? null) || ! filled($this->settings['password'] ?? null)) {
            throw new BusinessRuleViolation('The IMAP settings are incomplete.');
        }
        $socket = @stream_socket_client('tls://'.$host.':'.(int) ($this->settings['port'] ?? 993), $errno, $error, 20);
        if ($socket === false) {
            throw new BusinessRuleViolation("Could not reach the mail server {$host} ({$error}).");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, 30);
        try {
            $this->line(); // greeting
            $this->command('LOGIN '.self::quote((string) $this->settings['username']).' '.self::quote((string) $this->settings['password']), 'The mail server refused the username or app password.');
            $this->command('EXAMINE INBOX', 'The inbox could not be opened.');
            $found = $this->command('UID SEARCH SINCE '.$since->format('d-M-Y'), 'The inbox could not be searched.');
            preg_match('/\* SEARCH([\d ]*)/', $found, $m);
            $uids = array_slice(array_reverse(array_values(array_filter(explode(' ', trim($m[1] ?? ''))))), 0, $limit);
            $messages = [];
            foreach ($uids as $uid) {
                $raw = $this->command("UID FETCH {$uid} (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE MESSAGE-ID)] BODY.PEEK[TEXT])", 'An e-mail could not be read.');
                $messages[] = self::message((string) $uid, $raw);
            }
            $this->command('LOGOUT', '');

            return array_values(array_filter($messages, fn (MailMessage $msg) => $msg->receivedAt === null || $msg->receivedAt->gte($since)));
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    private static function message(string $uid, string $raw): MailMessage
    {
        $header = fn (string $name) => preg_match('/^'.$name.':\s*(.+(?:\r?\n[ \t].+)*)/mi', $raw, $m) === 1
            ? trim((string) iconv_mime_decode((string) preg_replace('/\r?\n[ \t]+/', ' ', $m[1]), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')) : '';
        $body = preg_match('/BODY\[TEXT\] \{\d+\}\r?\n(.*)\)\r?\n[A-Z]\d+ OK/s', $raw, $m) === 1 ? $m[1] : '';
        if (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $body) === 1 || str_contains($body, "=\r\n")) {
            $body = quoted_printable_decode($body);
        }
        $html = stripos($body, '<html') !== false || stripos($body, '<td') !== false;
        $date = $header('Date');

        return new MailMessage(
            $header('Message-ID') ?: 'imap-'.$uid,
            $header('From'),
            $header('Subject'),
            $date !== '' ? (rescue(fn () => CarbonImmutable::parse($date), null, false)) : null,
            MailMessage::textOf($body, $html),
        );
    }

    /** Sends a command and reads up to its tagged answer, which must be OK. */
    private function command(string $command, string $failure): string
    {
        $tag = 'A'.(++$this->tag);
        fwrite($this->socket, $tag.' '.$command."\r\n");
        $out = '';
        while (($line = $this->line()) !== null) {
            $out .= $line;
            if (str_starts_with($line, $tag.' ')) {
                if (! str_starts_with($line, $tag.' OK') && $failure !== '') {
                    throw new BusinessRuleViolation($failure);
                }

                return $out;
            }
        }
        throw new BusinessRuleViolation($failure !== '' ? $failure : 'The mail server stopped answering.');
    }

    private function line(): ?string
    {
        $line = fgets($this->socket);

        return $line === false ? null : $line;
    }

    private static function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
