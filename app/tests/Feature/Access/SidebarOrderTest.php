<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use Filament\Facades\Filament;
use Tests\Support\Make;

it('shows the sidebar in the order the business works, daily work before set-up', function () {
    $entity = Make::entity('NAV');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = Make::account($entity, 'SUPER_ADMIN');
    $this->actingAs($admin, 'web')->withSession(Make::webSession($admin));
    app(AuthContextBinder::class)->bind($admin);

    $html = $this->get('/admin')->assertOk()->getContent();
    $positions = function (array $labels) use ($html): array {
        return array_map(function (string $label) use ($html): int {
            $at = strpos($html, '>'.$label.'<') ?: strpos($html, $label);
            expect($at)->not->toBeFalse("'{$label}' is not in the sidebar");

            return (int) $at;
        }, $labels);
    };

    // The groups, in sequence: the headings as the sidebar shows them.
    preg_match_all('/fi-sidebar-group-label[^"]*"\s*>\s*([^<]+?)\s*</', $html, $m);
    $headings = array_map(fn (string $h) => html_entity_decode($h), $m[1]);
    expect($headings)->toBe(['Procurement', 'Gate & weighbridge', 'Stock & production', 'Sales & dispatch', 'Claims & pricing',
        'Accounts', 'HR', 'Daily wages', 'Reports', 'Masters', 'Administration', 'Security']);
    $groups = $positions(['Reports', 'Masters']);

    // Inside a group: the vehicle's path first, set-up last.
    $gate = $positions(['Vehicles at gate', 'Live weight', 'Negotiation', 'Weight checks', 'Lab tests &amp; limits', 'Weighbridges', 'Connect a weighbridge']);
    $sortedGate = $gate;
    sort($sortedGate);
    expect($gate)->toBe($sortedGate);

    // Every report in one place.
    $reports = $positions(['All reports (print in one click)', 'Complete business report', 'Profit &amp; loss', 'Books &amp; reports', 'Operations reports']);
    expect(min($reports))->toBeGreaterThan($groups[0])->and(max($reports))->toBeLessThan(strpos($html, '>Parties<') ?: PHP_INT_MAX);
});
