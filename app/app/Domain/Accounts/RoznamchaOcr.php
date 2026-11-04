<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Assistant\AiGateway;
use App\Domain\Assistant\ClaudeEngine;
use App\Models\Accounts\LedgerAccount;
use App\Models\Masters\Party;
use App\Support\Values\UrduDigits;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads a scanned roznamcha page into text and suggested lines, for a person to correct.
 *
 *  tesseract  on the mill's own server; English and figures, and Urdu when the "urd" language data is installed
 *             (RICEMILL_OCR_LANG=auto, the default, uses eng+urd whenever urd is there).
 *  claude     the AI reader, for handwriting and Urdu: it reads the whole page, both columns. The page image is
 *             sent to Anthropic's API with the mill's AI key (Administration → AI assistant).
 *  auto       (default) the AI reader when an AI key is saved, else tesseract.
 *  none       no reading: the lines are typed.
 *
 * A failure never stops the page from being saved: it is then typed by hand.
 *
 * Urdu: names stay in Urdu as written (account), with the same name in English letters (account_en) and, when
 * the AI recognises it, the mill's own party or account name (match), so Urdu names find their parties. Urdu
 * digits (۱۲۳) and the words جمع / بنام are read like 123 and jama / banam.
 */
final class RoznamchaOcr
{
    /** Entries kept from one page. */
    public const MAX_LINES = 200;

    /** Names sent to the AI reader so it can tell which party or account an Urdu name means. */
    private const KNOWN_NAMES = 400;

    /** @return array{engine: string, text: string, lines: list<array{account: string, debit: string, credit: string, narration: string, reference: string, account_en?: string, match?: string}>, error: string|null, date?: string|null} */
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
            $process = new Process([(string) config('ricemill.ocr.tesseract_path', 'tesseract'), $file, 'stdout', '-l', self::tesseractLang(), '--psm', '6']);
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
            $row = UrduDigits::toLatin($row);
            // The amount at the end (English pages) or at the start (Urdu is written right to left).
            if (preg_match('/^(?<account>.*?\pL.*?)[\s:.\-]+(?<amount>\d{1,3}(?:,\d{2,3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)\s*\/?-?\s*$/u', trim($row), $m) !== 1
                && preg_match('/^\s*\/?-?\s*(?<amount>\d{1,3}(?:,\d{2,3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)\s*\/?-?[\s:.\-]+(?<account>.*?\p{Arabic}.*?)\s*$/u', trim($row), $m) !== 1) {
                continue;
            }
            $amount = str_replace(',', '', $m['amount']);
            if ((float) $amount <= 0) {
                continue;
            }
            // Whole words in any script ((*UCP): Urdu letters count as letters).
            $out = preg_match('/(*UCP)\b(banam|dr|debit|paid|payment|ادا|بنام|دیا|دئیے|خرچ)\b/iu', $row) === 1;
            $account = (string) preg_replace('/(*UCP)\b(jama|banam|cr|dr|credit|debit|received|paid|جمع|بنام|وصول|ادا|دیا|دئیے|خرچ|بابت|روپے)\b/iu', '', $m['account']);
            // Trimmed letter by letter: a byte trim would cut an Urdu letter.
            $account = (string) preg_replace('/^[\s:.\-،]+|[\s:.\-،]+$/u', '', (string) preg_replace('/\s{2,}/u', ' ', $account));
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
        $known = self::knownNames();
        $prompt = 'This is a photo or scan of one page of a Pakistani rice mill\'s daily cash book (roznamcha), handwritten or printed, '
            .'in Urdu (Nastaliq script, written right to left), English or Roman Urdu, often mixed. The photo may be tilted or taken from a phone: '
            .'read the COMPLETE page, top to bottom, every row in every column. Read Urdu carefully letter by letter; keep Urdu words in Urdu '
            .'script exactly as written (do not translate them in "account", "narration" or "text"). '
            .'A roznamcha usually has two sides: "jama" / جمع (cash received, credit; on an Urdu page usually the right-hand side) and '
            .'"banam" / بنام (cash paid, debit; usually the left-hand side); some pages have one list with an amount and a word saying which '
            .'(وصول / جمع = received, ادا / دیا / بنام / خرچ = paid). Skip only headings, dates, page totals, "baqaya" / بقایا / balance brought '
            .'or carried forward and grand totals (میزان / ٹوٹل): they are not entries. For each entry give the party or account name exactly '
            .'as written (a person, firm, or an expense such as diesel / ڈیزل, bijli / بجلی, mazdoori / مزدوری, kharcha / خرچہ), the same '
            .'name in English letters as a Pakistani would spell it (e.g. حاجی اسلم → Haji Aslam), what it was for, and any bill, slip or cheque '
            .'number. Amounts in digits only: Urdu digits ۰۱۲۳۴۵۶۷۸۹ turned into 0123456789; "15,000/-" and "۱۵۰۰۰" are 15000. '
            .($known !== [] ? 'These are the mill\'s own parties and accounts; when an entry clearly means one of them (also when written in Urdu), '
                .'put that name exactly in "match", else leave "match" empty: '.json_encode($known, JSON_UNESCAPED_UNICODE).'. ' : '')
            .'Return only JSON, no other words, with "lines" FIRST: '
            .'{"lines": [{"account": "name as written (Urdu stays Urdu)", "account_en": "the name in English letters", "match": "the mill\'s name it means, or empty", '
            .'"credit": "cash received (jama) or empty", "debit": "cash paid (banam) or empty", '
            .'"narration": "what it was for, as written, or empty", "reference": "bill / slip / cheque number, or empty"}], '
            .'"date": "the date written on the page, YYYY-MM-DD, or empty", "text": "the whole page as plain text, line by line, as written"}. '
            .'Each line has either credit or debit, never both. Do not invent entries; if a figure cannot be read, leave that entry out of '
            .'"lines" but keep it in "text".';
        // Any provider (AiGateway); the model chosen must read pictures (most do).
        $ai = ClaudeEngine::settings();
        $engine = $ai['provider'] === 'anthropic' ? 'claude' : 'ai';
        $response = app(AiGateway::class)->send($ai, null, [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => $prompt]]]], [], 12000, 180);
        if (! $response->successful()) {
            return ['engine' => 'none', 'text' => '', 'lines' => [], 'error' => 'The AI reader did not answer ('.AiGateway::error($response).'); type the lines.'];
        }
        $answer = (string) collect(AiGateway::answer($ai, $response)['content'])->where('type', 'text')->pluck('text')->implode('');
        $json = json_decode((string) preg_replace('/^[^{]*|[^}]*$/s', '', $answer), true);
        if (! is_array($json)) {
            // A long Urdu page may be cut off before the end of the answer: keep every entry that came whole.
            $json = ['lines' => self::wholeEntries($answer), 'text' => ''];
            if ($json['lines'] === []) {
                return ['engine' => $engine, 'text' => $answer, 'lines' => [], 'error' => 'The page was read but its lines could not be separated; type them.'];
            }
        }
        $text = fn ($v, int $max) => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $max);
        $lines = array_values(array_map(fn ($l) => [
            'account' => $text($l['account'] ?? '', 160),
            'account_en' => $text($l['account_en'] ?? '', 160),
            'match' => $text($l['match'] ?? '', 160),
            'debit' => UrduDigits::amount((string) ($l['debit'] ?? '')), 'credit' => UrduDigits::amount((string) ($l['credit'] ?? '')),
            'narration' => $text($l['narration'] ?? '', 300), 'reference' => UrduDigits::toLatin($text($l['reference'] ?? '', 80)),
        ], array_filter((array) ($json['lines'] ?? []), 'is_array')));
        $date = UrduDigits::toLatin(trim((string) (is_string($json['date'] ?? null) ? $json['date'] : '')));

        return ['engine' => $engine, 'text' => (string) ($json['text'] ?? ''), 'lines' => array_slice($lines, 0, self::MAX_LINES), 'error' => null,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null];
    }

    /**
     * The entries that came whole from an answer cut off part way.
     *
     * @return list<array<string, mixed>>
     */
    private static function wholeEntries(string $answer): array
    {
        preg_match_all('/\{[^{}]*"account"[^{}]*\}/u', $answer, $m);

        return array_values(array_filter(array_map(fn (string $o) => json_decode($o, true), $m[0]), 'is_array'));
    }

    /**
     * The mill's party and account names, for the AI to tell which one an Urdu name means.
     *
     * @return list<string>
     */
    private static function knownNames(): array
    {
        try {
            $parties = Party::query()->where('status', 'active')->orderBy('name')->limit(self::KNOWN_NAMES)->pluck('name')->all();
            $accounts = LedgerAccount::query()->where('status', 'active')->whereIn('kind', ['general', 'system'])->whereNull('party_id')
                ->orderBy('name')->limit(150)->pluck('name')->all();

            return array_values(array_unique(array_map('strval', [...$parties, ...$accounts])));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** "auto": English and Urdu when the Urdu language data is installed, else English. */
    private static function tesseractLang(): string
    {
        $lang = (string) config('ricemill.ocr.lang', 'auto');
        if ($lang !== 'auto') {
            return $lang;
        }

        return Cache::remember('roznamcha.tesseract.lang', 3600, function (): string {
            try {
                $p = new Process([(string) config('ricemill.ocr.tesseract_path', 'tesseract'), '--list-langs']);
                $p->setTimeout(10)->run();

                return preg_match('/^urd$/m', $p->getOutput().$p->getErrorOutput()) === 1 ? 'eng+urd' : 'eng';
            } catch (Throwable) {
                return 'eng';
            }
        });
    }
}
