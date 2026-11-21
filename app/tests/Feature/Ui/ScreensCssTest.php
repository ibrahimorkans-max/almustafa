<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use Filament\Facades\Filament;
use Tests\Support\Make;

/**
 * Filament's prebuilt stylesheet lacks our screens' classes; public/css/millnexa/screens.css carries
 * them. If a view gains a class and nobody ran `npm run build:screens`, this says which.
 */
it('has every responsive class our screens use in the built stylesheet', function () {
    $css = (string) file_get_contents(public_path('css/millnexa/screens.css'));
    $missing = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
        if (! str_ends_with((string) $file, '.blade.php') || str_ends_with((string) $file, 'welcome.blade.php')) {
            continue;
        }
        preg_match_all('/(?<![\w:-])((?:sm|md|lg|xl|2xl):(?:grid-cols|col-span)-\d+)(?![\w-])/', (string) file_get_contents((string) $file), $m);
        foreach ($m[1] as $class) {
            if (! str_contains($css, '.'.str_replace(':', '\:', $class).'{')) {
                $missing[$class] = basename((string) $file);
            }
        }
    }

    expect($missing)->toBe([]);
});

it('loads it on every screen, after Filament\'s own', function () {
    $entity = Make::entity('CSS');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $owner = Make::account($entity, 'OWNER');
    $this->actingAs($owner, 'web')->withSession(Make::webSession($owner));
    app(AuthContextBinder::class)->bind($owner);

    $html = $this->get('/admin/features-guide')->assertOk()->getContent();
    $ours = strpos($html, 'css/millnexa/screens.css');
    expect($ours)->not->toBeFalse()
        ->and($ours)->toBeGreaterThan(strpos($html, 'css/filament/filament/app.css'));
});
