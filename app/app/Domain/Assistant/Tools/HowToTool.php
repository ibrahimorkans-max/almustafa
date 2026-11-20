<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Models\LoginAccount;
use App\Support\FeatureDescriptions;
use App\Support\FeatureHowTo;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Throwable;

/**
 * "How do I make a quick bill?", "salary voucher kaise banate hain?": the purpose of the screen, how it works and the
 * steps, from the Features guide, with a link to open it. Only screens this person may open are explained.
 */
final class HowToTool extends Tool
{
    private const STOP = ['how', 'what', 'does', 'work', 'works', 'use', 'the', 'and', 'for', 'make', 'can', 'kaise', 'kese', 'kya', 'hai', 'hain',
        'karte', 'karein', 'banate', 'banaen', 'banana', 'guide', 'explain', 'purpose', 'screen', 'feature', 'with', 'from', 'this', 'that', 'into', 'about'];

    public function name(): string
    {
        return 'how_to';
    }

    public function description(): string
    {
        return 'Explain a feature of the ERP from the Features guide: what it is for, how it works and how to do it step by step, with a link to the screen. '
            .'Use it for any "how do I…", "how does … work", "what is … for" or "kaise" question, e.g. "how do I make a quick bill", "salary voucher", "stock report", "forgot password".';
    }

    public function properties(): array
    {
        return ['topic' => ['type' => 'string', 'description' => 'The feature or task the person asks about, in their words.']];
    }

    public function required(): array
    {
        return ['topic'];
    }

    public function patterns(): array
    {
        return [
            '/^(?:how (?:do|can|should) (?:i|we|you)|how to|how does|how is|explain|guide(?: for| to)?|help (?:with|on))\s+(?<topic>.{2,80}?)\??$/i',
            '/^what(?: is|\'s| are)\s+(?:the\s+)?(?<topic>.{2,60}?)\s+(?:for|used for)\??$/i',
            '/^(?<topic>.{2,60}?)\s+(?:kaise|kese|kis tarah)\b.*$/iu',
        ];
    }

    public function examples(): array
    {
        return ['how do I make a quick bill', 'salary voucher kaise banate hain'];
    }

    /**
     * Screens this person may open that the guide explains.
     *
     * @return list<array{class: string, label: string, url: string|null}>
     */
    public static function screens(): array
    {
        $panel = Filament::getPanel('admin');
        $out = [];
        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            if (! isset(FeatureHowTo::ALL[class_basename($class)])) {
                continue;
            }
            try {
                $allowed = is_subclass_of($class, Resource::class) ? $class::canViewAny() : (is_subclass_of($class, Page::class) && $class::canAccess());
                if (! $allowed) {
                    continue;
                }
                try {
                    $url = $class::getUrl();
                } catch (Throwable) {
                    $url = null;
                }
                $out[] = ['class' => $class, 'label' => (string) $class::getNavigationLabel(), 'url' => $url];
            } catch (Throwable) {
                continue;
            }
        }

        return $out;
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $topic = mb_strtolower((string) ($input['topic'] ?? ''));
        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $topic) ?: [], fn ($w) => mb_strlen($w) > 2 && ! in_array($w, self::STOP, true))));
        if ($words === []) {
            return ToolResult::fail('Tell me which feature, e.g. "how do I make a quick bill".');
        }
        $scored = [];
        foreach (self::screens() as $s) {
            $entry = FeatureHowTo::ALL[class_basename($s['class'])];
            $title = mb_strtolower($s['label'].' '.class_basename($s['class']));
            $keys = mb_strtolower(FeatureHowTo::words($s['class']));
            $body = mb_strtolower((FeatureDescriptions::ALL[class_basename($s['class'])] ?? '').' '.$entry['how']);
            $score = 0;
            foreach (array_map('trim', explode(',', $keys)) as $phrase) {
                $score += str_contains($phrase, ' ') && str_contains($topic, $phrase) ? 8 : 0;   // "godown to godown", "walk in"
            }
            foreach ($words as $w) {
                $stem = preg_replace('/(ies|es|s)$/u', '', $w) ?: $w;
                $score += (str_contains($title, $stem) ? 5 : 0) + (str_contains($keys, $stem) ? 3 : 0) + (str_contains($body, $stem) ? 1 : 0);
            }
            if ($score > 0) {
                $scored[] = [$score, $s];
            }
        }
        if ($scored === []) {
            return new ToolResult('I have no guide for that. Open "Features guide" in the menu to see every screen, or ask me to open a screen.');
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);
        [, $best] = $scored[0];
        $how = FeatureHowTo::for($best['class']);
        $lines = [$best['label'].': '.(FeatureDescriptions::for($best['class']) ?? ''), '', __('How it works').': '.$how['how'], '', __('Steps').':'];
        foreach ($how['steps'] as $i => $step) {
            $lines[] = ($i + 1).'. '.$step;
        }
        $links = $best['url'] === null ? [] : [['label' => __('Open :screen', ['screen' => $best['label']]), 'url' => $best['url']]];
        foreach (array_slice($scored, 1, 2) as [, $other]) {
            if ($other['url'] !== null) {
                $links[] = ['label' => __('Also: :screen', ['screen' => $other['label']]), 'url' => $other['url']];
            }
        }

        return new ToolResult(implode("\n", $lines), $links);
    }
}
