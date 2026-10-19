<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Throwable;

/**
 * Every screen of the system this person may open, found by words ("open payments", "new sauda",
 * "salary"). This is how the assistant reaches any feature it has no own action for.
 */
final class OpenScreenTool extends Tool
{
    public function name(): string
    {
        return 'open_screen';
    }

    public function description(): string
    {
        return 'Find the screen for any feature of the ERP (and its "new" form) by words, e.g. "payments", "new sauda", "payslips", "dispatch". Returns links the person can click.';
    }

    public function properties(): array
    {
        return ['words' => ['type' => 'string', 'description' => 'What the person wants to open or do.']];
    }

    public function required(): array
    {
        return ['words'];
    }

    public function patterns(): array
    {
        return ['/^(?:open|go to|show me|where is|screen|kholo|new|create|add)\s+(?<words>.{2,60})$/i'];
    }

    public function examples(): array
    {
        return ['open payments', 'new sauda'];
    }

    /** @return list<array{label: string, group: string, url: string, create: string|null}> */
    public static function screens(): array
    {
        $panel = Filament::getPanel('admin');
        $out = [];
        foreach ($panel->getResources() as $resource) {
            /** @var class-string<resource> $resource */
            try {
                if (! $resource::canViewAny() || ! $resource::shouldRegisterNavigation()) {
                    continue;
                }
                $out[] = ['label' => (string) $resource::getNavigationLabel(), 'group' => (string) $resource::getNavigationGroup(), 'url' => $resource::getUrl(),
                    'create' => $resource::hasPage('create') && $resource::canCreate() ? $resource::getUrl('create') : null];
            } catch (Throwable) {
                continue;
            }
        }
        foreach ($panel->getPages() as $page) {
            /** @var class-string<Page> $page */
            try {
                if (! $page::canAccess() || ! $page::shouldRegisterNavigation()) {
                    continue;
                }
                $out[] = ['label' => (string) $page::getNavigationLabel(), 'group' => (string) $page::getNavigationGroup(), 'url' => $page::getUrl(), 'create' => null];
            } catch (Throwable) {
                continue;
            }
        }

        return $out;
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $words = array_values(array_filter(preg_split('/\W+/u', mb_strtolower((string) ($input['words'] ?? ''))) ?: [], fn ($w) => mb_strlen($w) > 2 && ! in_array($w, ['the', 'new', 'open', 'create', 'add', 'screen', 'page'], true)));
        $wantsNew = (bool) preg_match('/\b(new|create|add|make)\b/i', (string) ($input['words'] ?? ''));
        $scored = [];
        foreach (self::screens() as $s) {
            $hay = mb_strtolower($s['label'].' '.$s['group']);
            $score = 0;
            foreach ($words as $w) {
                $stem = rtrim($w, 's');
                $score += str_contains($hay, $stem) ? 2 : 0;
            }
            if ($score > 0) {
                $scored[] = [$score, $s];
            }
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);
        $links = [];
        foreach (array_slice($scored, 0, 5) as [, $s]) {
            if ($wantsNew && $s['create'] !== null) {
                $links[] = ['label' => 'New: '.$s['label'], 'url' => $s['create']];
            }
            $links[] = ['label' => trim($s['group'].' → '.$s['label'], ' →'), 'url' => $s['url']];
        }

        return $links === [] ? new ToolResult('I found no screen for that. Say "help" to see what I can do, or use the menu on the left.')
            : new ToolResult('Here you go:', array_slice($links, 0, 6));
    }
}
