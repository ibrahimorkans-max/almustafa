<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\TranslatesLabels;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\FeatureDescriptions;
use App\Support\FeatureHowTo;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Every screen this person may open, by menu group, with one line on what it is for — so a new user
 * finds the right screen without being shown around.
 */
class FeaturesGuide extends Page
{
    use TranslatesLabels;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Features guide';

    protected static ?string $title = 'Features guide';

    protected static ?string $slug = 'features-guide';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.features-guide';

    protected ?string $subheading = 'What each screen is for and how to use it. Search, press "How to use" for the steps, or click a screen to open it.';

    /** @return array<string, list<array{label: string, url: string, description: string|null, icon: string|null, how: array{how: string, steps: list<string>}|null}>> */
    public function groups(): array
    {
        $panel = Filament::getCurrentPanel();
        $items = [];
        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            if ($class === self::class || ! $class::shouldRegisterNavigation()) {
                continue;
            }
            $allowed = is_subclass_of($class, \Filament\Resources\Resource::class) ? $class::canViewAny() && $class::canAccess() : $class::canAccess();
            if (! $allowed) {
                continue;
            }
            try {
                $url = $class::getUrl();
            } catch (\Throwable) {
                continue;
            }
            $group = $class::getNavigationGroup() ?? '';
            $items[$group][] = ['label' => $class::getNavigationLabel(), 'url' => $url, 'description' => FeatureDescriptions::for($class),
                'icon' => $class::getNavigationIcon(), 'how' => FeatureHowTo::for($class)];
        }
        // In the menu's order; screens without a group first.
        $order = ['' => -1] + array_flip(array_map(fn (string $g) => __($g), AdminPanelProvider::NAVIGATION_GROUPS));
        uksort($items, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        foreach ($items as &$list) {
            usort($list, fn ($a, $b) => strcmp($a['label'], $b['label']));
        }

        return $items;
    }
}
