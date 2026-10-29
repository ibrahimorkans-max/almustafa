<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Models\Banking\BankEmailTemplate;
use Carbon\CarbonImmutable;

/**
 * Reads one notification e-mail with the template of the bank that sent it. A template applies when its
 * sender pattern matches the From address (and its subject pattern, if any, the subject). The result is
 * what could be read; an e-mail with no amount, or no way to tell money in from money out, is a failure.
 */
final class BankEmailParser
{
    /**
     * @param  iterable<BankEmailTemplate>  $templates
     * @return array{template: BankEmailTemplate|null, fields: array<string, mixed>, error: string|null}
     */
    public function parse(MailMessage $message, iterable $templates): array
    {
        foreach ($templates as $t) {
            if (! $t->active || ! self::test($t->sender_pattern, $message->from)) {
                continue;
            }
            if ($t->subject_pattern !== null && $t->subject_pattern !== '' && ! self::test($t->subject_pattern, $message->subject)) {
                continue;
            }

            return ['template' => $t] + $this->read($t, $message);
        }

        return ['template' => null, 'fields' => [], 'error' => 'No bank template matches this sender.'];
    }

    /** @return array{fields: array<string, mixed>, error: string|null} */
    private function read(BankEmailTemplate $t, MailMessage $message): array
    {
        $text = $message->subject."\n".$message->text;
        $amount = self::group($t->amount_pattern, $text, 'amount');
        $paise = $amount === null ? null : self::paise($amount);
        $direction = match (true) {
            $t->credit_pattern !== null && $t->credit_pattern !== '' && self::test($t->credit_pattern, $text) => 'credit',
            $t->debit_pattern !== null && $t->debit_pattern !== '' && self::test($t->debit_pattern, $text) => 'debit',
            default => null,
        };
        $at = self::date($t, $text) ?? $message->receivedAt?->timezone('Asia/Karachi');

        $fields = [
            'bank_name' => $t->bank_name,
            'amount_paise' => $paise,
            'direction' => $direction,
            'reference' => self::clip(self::group($t->reference_pattern, $text), 80),
            'account_hint' => self::clip(self::group($t->account_pattern, $text), 40),
            'counterparty' => self::clip(self::group($t->counterparty_pattern, $text), 160),
            'counterparty_account' => self::clip(self::group($t->counterparty_account_pattern, $text), 40),
            'txn_date' => $at?->toDateString(),
            'txn_time' => $at?->format('H:i:s'),
        ];
        $error = match (true) {
            $paise === null || $paise <= 0 => 'The amount could not be read.',
            $direction === null => 'Could not tell money in from money out.',
            default => null,
        };

        return ['fields' => $fields, 'error' => $error];
    }

    /** "Rs. 1,25,000.50" → 12500050 paise. */
    public static function paise(string $amount): ?int
    {
        $clean = (string) preg_replace('/[^\d.]/', '', $amount);
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $clean)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $clean), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function date(BankEmailTemplate $t, string $text): ?CarbonImmutable
    {
        $raw = self::group($t->date_pattern, $text);
        if ($raw === null) {
            return null;
        }

        return rescue(fn () => $t->date_format
            ? CarbonImmutable::createFromFormat($t->date_format, $raw, 'Asia/Karachi') ?: null
            : CarbonImmutable::parse($raw, 'Asia/Karachi'), null, false);
    }

    private static function test(string $pattern, string $subject): bool
    {
        return @preg_match(self::delimit($pattern), $subject) === 1;
    }

    /** The named group (or "value", or the first group) of the first match. */
    private static function group(?string $pattern, string $subject, string $name = 'value'): ?string
    {
        if ($pattern === null || $pattern === '' || @preg_match(self::delimit($pattern), $subject, $m) !== 1) {
            return null;
        }
        $value = $m[$name] ?? $m['value'] ?? $m[1] ?? null;

        return $value === null || trim($value) === '' ? null : trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private static function delimit(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }

    private static function clip(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
