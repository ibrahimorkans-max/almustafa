<?php

declare(strict_types=1);

use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\RoleTemplates;
use App\Domain\Auth\AuthContextBinder;
use App\Filament\Pages\MyDocumentsPage;
use App\Filament\Pages\SwitchContext;
use App\Filament\Resources\Pricing\PriceListResource\Pages\CreatePriceList;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Http\Middleware\RequireWorkingEntityForCreate;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('SAA'));
    $this->f->acc->receiveLot();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->sa = Make::owner(Make::entity('HOME'));
    $this->session = Make::webSession($this->sa);
});

/**
 * Every resource and page URL; record pages use a record the resource itself lists. Personal pages (My
 * documents) are left out: they show a login its own employee records, and a Super Admin has none.
 *
 * @return array<string, string>
 */
function adminUrls(LoginAccount $sa, ?string $workingEntity): array
{
    auth('web')->setUser($sa);
    app(AuthContextBinder::class)->bind($sa, null, null, $workingEntity);
    $urls = [];
    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $record = $resource::getEloquentQuery()->first();
        foreach (array_keys($resource::getPages()) as $page) {
            if (in_array($page, ['index', 'create'], true)) {
                $urls[$resource::getUrl($page)] = $page;
            } elseif ($record !== null) {
                $urls[$resource::getUrl($page, ['record' => $record])] = $page;
            }
        }
    }
    foreach (array_diff(Filament::getPanel('admin')->getPages(), [MyDocumentsPage::class]) as $page) {
        $urls[$page::getUrl()] = 'page';
    }
    Make::reset();

    return $urls;
}

it('holds every catalogued permission except the "own records only" narrowing ones', function () {
    $missing = array_values(array_diff(PermissionCatalog::codes(), Make::system(fn () => $this->sa->fresh()->permissionCodes())));
    sort($missing);
    expect($missing)->toBe(['commission.view_own', 'deal.view_own'])
        ->and(RoleTemplates::get(RoleTemplates::OWNER)['permissions'])->not->toContain('deal.view_own');
});

it('opens every admin screen of the working firm without a 403 or an error', function () {
    $session = $this->session + [EnsureWebAuthSession::WORKING_ENTITY_KEY => $this->f->entity->id];
    $failures = [];
    foreach (adminUrls($this->sa, $this->f->entity->id) as $url => $kind) {
        $status = $this->actingAs($this->sa, 'web')->withSession($session)->get($url)->status();
        if ($status !== 200) {
            $failures[] = "{$status} {$kind} ".parse_url($url, PHP_URL_PATH);
        }
    }
    expect($failures)->toBe([])->and(count(adminUrls($this->sa, $this->f->entity->id)))->toBeGreaterThan(60);

    // No employee record behind a Super Admin, so no personal documents to show.
    $this->actingAs($this->sa, 'web')->withSession($session)->get(MyDocumentsPage::getUrl())->assertForbidden();
});

it('in "All firms" mode opens every list and sends create screens to the firm switcher with an explanation', function () {
    $session = $this->session + [EnsureWebAuthSession::WORKING_ENTITY_KEY => AuthContextBinder::ALL_ENTITIES];
    $failures = [];
    foreach (adminUrls($this->sa, AuthContextBinder::ALL_ENTITIES) as $url => $kind) {
        $res = $this->actingAs($this->sa, 'web')->withSession($session)->get($url);
        $expected = $kind === 'create' ? 302 : 200;
        if ($res->status() !== $expected || ($kind === 'create' && $res->headers->get('Location') !== SwitchContext::getUrl())) {
            $failures[] = "{$res->status()} {$kind} ".parse_url($url, PHP_URL_PATH);
        }
    }
    expect($failures)->toBe([]);

    $this->actingAs($this->sa, 'web')->withSession($session)->get('/admin/batches/open')->assertRedirect(SwitchContext::getUrl());
    $this->get(SwitchContext::getUrl())->assertSee('Choose the working entity first');
});

it('explains instead of crashing when a write needs a firm in "All firms" mode', function () {
    $this->actingAs($this->sa, 'web')->withSession($this->session + [EnsureWebAuthSession::WORKING_ENTITY_KEY => AuthContextBinder::ALL_ENTITIES]);
    app(AuthContextBinder::class)->bind($this->sa, null, null, AuthContextBinder::ALL_ENTITIES);

    Livewire::test(CreatePriceList::class)
        ->fillForm(['name' => 'Banaspati', 'effective_from' => now()->format('Y-m-d')])
        ->call('create')
        ->assertNotified(RequireWorkingEntityForCreate::MESSAGE);
});
