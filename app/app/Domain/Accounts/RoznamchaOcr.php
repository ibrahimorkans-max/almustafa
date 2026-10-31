<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Assistant\AiGateway;
use App\Domain\Assistant\ClaudeEngine;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads a scanned roznamcha page into text and suggested lines, for a person to correct.
 *
 *  tesseract  (default) on the mill's own server; English and figures. Urdu needs the "urd" language
 *             data installed (RICEMILL_OCR_LANG=eng+urd).
 *  claude     the AI reader, for handwriting and Urdu: it reads the whole page, both columns. The page image is
 *             sent to Anthropic's API with the mill's AI key (Administration → AI assistant).
 *  auto       (default) the AI reader when an AI key is saved, else tesseract.
 *  none       no reading: the lines are typed.
 *
 * A failure never stops the page from being saved: it is then typed by hand.
 */
final class RoznamchaOcr
{
    /** Entries kept from one page. */
    public const MAX_LINES = 200;

    /** @return array{engine: string, text: string, lines: list<array{account: string, debit: string, credit: string, narration: string, reference: string}>, error: string|null, date?: string|null} */
    public function read(string $bytes, string $mime): array
    {
        $engine = (string) config('ricemill.ocr.engine', 'auto');
        try {
            if (in_array($engine, ['claude', 'auto'], true) && filled(ClaudeEngine::settings()['api_key'])) {
                return $this->claude($bytes, $mime);
            }
            if (in_array($engine, ['tesseract', 'claude', 'auto'], true) && in_array($mime, ['image/png', 'image/jpeg'], true)) {
                return $this->tesseract($bytes, $mime);
            }
        } catch (Throwable $e) {
            report($e);

            return ['engine' => 'none', 'text' => '', 'lines' => [], 'error' => 'The page could not be read automatically; type its lines.'];
        }

        return ['engine' => 'none', 'text' => '', 'lines' => [], 'error' => $mime === 'application/pdf' ? 'PDF pages are read by the AI reader only; type the lines or upload a photo.' : null];
    }

    /** @return array{engine: string, text: string, lines: list<array{account: string, debit: string, credit: string, narration: string, reference: string}>, error: string|null} */
    private function tesseract(string $bytes, string $mime): array
    {
        $file = tempnam(sys_get_temp_dir(), 'rzn').($mime === 'image/png' ? '.png' : '.jpg');
        file_put_contents($file, $bytes);
        try {
            $process = new Process([(string) config('ricemill.ocr.tesseract_path', 'tesseract'), $file, 'stdout', '-l', (string) config('ricemill.ocr.lang', 'eng'), '--psm', '6']);
            $process->setTimeout(60)->run();
            if (! $process->isSuccessful()) {
                return ['engine' => 'none', 'text' => '', 'lines' => [], 'error' => 'The OCR program could not read the page; type its lines.'];
            }
            $text = trim($process->getOutput());
        } finally {
            @unlink($file);
        }

        return ['engine' => 'tesseract', 'text' => $text, 'lines' => self::linesFromText($text), 'error' => null];
    }

    /**
     * Lines that end in an amount become suggestions: the words before it are the account. "Jama / Cr /
     * received" marks money in; "Banam / Dr / paid" money out; otherwise it is left as received.
     *
     * @return list<array{account: string, debit: string, credit: string, narration: string, reference: string}>
     */
    public static function linesFromText(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $row) {
            if (preg_match('/^(?<account>.*?\pL.*?)[\s:.-]+(?<amount>\d{1,3}(?:,\d{2,3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)\s*\/?-?\s*$/u', trim($row), $m) !== 1) {
                continue;
            }
            $amount = str_replace(',', '', $m['amount']);
            if ((float) $amount <= 0) {
                continue;
            }
            $out = preg_match('/\b(banam|dr|debit|paid|payment|ادا|بنام)\b/iu', $row) === 1;
            $account = trim((string) preg_replace('/\b(jama|banam|cr|dr|credit|debit|received|paid)\b/iu', '', $m['account']), " \t:-.");
            $lines[] = ['account' => mb_substr($account, 0, 160), 'debit' => $out ? $amount : '', 'credit' => $out ? '' : $amount, 'narration' => '', 'reference' => ''];
        }

        return array_slice($lines, 0, self::MAX_LINES);
    }

    /** @return array{engine: string, text: string, lines: list<array{account: string, debit: string, credit: string, narration: string, reference: string}>, error: string|null, date?: string|null} */
    private function claude(string $bytes, string $mime): array
    {
        $block = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];
        $prompt = 'This is a photo or scan of one page of a Pakistani rice mill\'s daily cash book (roznamcha), handwritten or printed, '
            .'in Urdu and/or English (Roman Urdu too). The photo may be tilted or taken from a phone: read the COMPLETE page, top to bottom, '
            .'every row in every column. A roznamcha usually has two sides: "jama" (cash received, credit) and "banam" (cash paid, debit); '
            .'some pages have one list with an amount and a word saying which. Skip only headings, dates, page totals, "baqaya" / balance '
            .'brought or carried forward and grand totals: they are not entries. For each entry give the party or account name exactly as '
            .'written (a person, firm, or an expense such as diesel, bijli, mazdoori, kharcha), what it was for, and any bill, slip or cheque '
            .'number. Amounts in digits only (Urdu digits turned into 0-9; "15,000/-" is 15000). Return only JSON, no other words: '
            .'{"date": "the date written on the page, YYYY-MM-DD, or empty", "text": "the whole page as plain text, line by line", '
            .'"lines": [{"account": "name as written", "credit": "cash received (jama) or empty", "debit": "cash paid (banam) or empty", '
            .'"narration": "what it was for, or empty", "reference": "bill / slip / cheque number, or empty"}]}. '
            .'Each line has either credit or debit, never both. Do not invent entries; if a figure cannot be read, leave that entry out of '
            .'"lines" but keep it in "text".';
        // Any provider (AiGateway); the model chosen must read pictures (most do).
        $ai = ClaudeEngine::settings();
        $engine = $ai['provider'] === 'anthropic' ? 'claude' : 'ai';
        $response = app(AiGateway::class)->send($ai, null, [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => $prompt]]]], [], 8000, 150);
        if (! $response->successful()) {
            return ['engine' => 'none', 'text' => '', 'lines' => [], 'error' => 'The AI reader did not answer ('.AiGateway::error($response).'); type the lines.'];
        }
        $answer = (string) collect(AiGateway::answer($ai, $response)['content'])->where('type', 'text')->pluck('text')->implode('');
        $json = json_decode((string) preg_replace('/^[^{]*|[^}]*$/s', '', $answer), true);
        if (! is_array($json)) {
            return ['engine' => $engine, 'text' => $answer, 'lines' => [], 'error' => 'The page was read but its lines could not be separated; type them.'];
        }
        $digits = fn ($v) => (string) preg_replace('/[^\d.]/', '', (string) $v);
        $lines = array_values(array_map(fn ($l) => [
            'account' => mb_substr((string) ($l['account'] ?? ''), 0, 160),
            'debit' => $digits($l['debit'] ?? ''), 'credit' => $digits($l['credit'] ?? ''),
            'narration' => mb_substr((string) ($l['narration'] ?? ''), 0, 300), 'reference' => mb_substr((string) ($l['reference'] ?? ''), 0, 80),
        ], array_filter((array) ($json['lines'] ?? []), 'is_array')));

        return ['engine' => $engine, 'text' => (string) ($json['text'] ?? ''), 'lines' => array_slice($lines, 0, self::MAX_LINES), 'error' => null,
            'date' => is_string($json['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $json['date']) === 1 ? $json['date'] : null];
    }
}
