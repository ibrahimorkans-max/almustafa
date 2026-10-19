<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Filament\Resources\Gate\GateEntryResource;
use Filament\Facades\Filament;
use Tests\Support\Make;

/** A mill server has no internet: no page may load scripts, styles or fonts from outside. */
it('loads every script, style and font of the admin pages from the server itself', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $entity = Make::entity('OFF');
    $admin = Make::account($entity, 'SUPER_ADMIN');
    $this->actingAs($admin, 'web')->withSession(Make::webSession($admin));
    app(AuthContextBinder::class)->bind($admin);

    foreach (['/admin', GateEntryResource::getUrl(), '/admin/login'] as $url) {
        $html = (string) $this->get($url)->getContent();
        preg_match_all('/<(?:script|link)\b[^>]*(?:src|href)="(https?:)?\/\/(?!localhost)[^"]+"/i', $html, $external);
        expect($external[0])->toBe([], "{$url} loads from the internet")
            ->and($html)->not->toContain('fonts.bunny.net');
    }
});
