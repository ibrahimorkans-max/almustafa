<?php

declare(strict_types=1);

use App\Domain\Access\RoleTemplates;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Security\AccessReview;
use App\Filament\Pages\AccessReviewPage;
use App\Models\AuditLog;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->entity = Make::entity('RLA');
});

dataset('back office roles', fn () => array_values(array_diff(array_keys(RoleTemplates::all()), ['OWNER', 'BROKER'])));

it('answers every screen a role may not open with an explaining 403 page, never an error or an empty page', function (string $role) {
    $account = Make::account($this->entity, $role, ['mfa' => true]);
    $this->actingAs($account, 'web')->withSession(Make::webSession($account));
    app(AuthContextBinder::class)->bind($account);
    $roleName = $account->role->name;

    $urls = collect(Filament::getPanel('admin')->getResources())->map(fn ($r) => $r::getUrl('index'))
        ->merge(collect(Filament::getPanel('admin')->getPages())->map(fn ($p) => $p::getUrl()))->unique();
    $forbidden = 0;
    foreach ($urls as $url) {
        $res = $this->get($url);
        if ($res->isRedirect()) {
            // Only the Office Coordinator's start page redirects: straight to the Office Desk.
            expect($role)->toBe('OFFICE_COORDINATOR')->and($res->headers->get('Location'))->toContain('/admin/office-desk');

            continue;
        }
        expect($res->status())->toBeIn([200, 403], "{$role} {$url}");
        if ($res->status() === 403) {
            $forbidden++;
            $res->assertSee('You do not have access to this page')->assertSee($roleName);
        }
    }
    expect($forbidden)->toBeGreaterThan(0);
})->with('back office roles');

it('lists accounts that need attention, with real sign-in facts', function () {
    $owner = Make::account($this->entity, 'SUPER_ADMIN');
    $dormant = Make::account($this->entity, 'SUPERVISOR');
    $never = Make::account($this->entity, 'LAB_TECHNICIAN');
    $pending = Make::account($this->entity, 'EXECUTIVE', ['must_change_password' => true]);
    $active = Make::account($this->entity, 'GATE_OPERATOR');
    Make::system(function () use ($dormant, $active, $owner) {
        $dormant->forceFill(['last_login_at' => now()->subDays(45)])->save();
        $active->forceFill(['last_login_at' => now()->subDay()])->save();
        $owner->forceFill(['last_login_at' => now()])->save();
    });
    app(AuthContextBinder::class)->bind($owner);

    $rows = collect(app(AccessReview::class)->rows())->keyBy('username');
    expect($rows[$dormant->username]['flags'])->toContain('dormant')
        ->and($rows[$never->username]['flags'])->toContain('never signed in')
        ->and($rows[$pending->username]['flags'])->toContain('setup pending')
        ->and($rows[$active->username]['flags'])->toBe('')
        ->and($rows[$owner->username]['permission_count'])->toBe(count($owner->permissionCodes()))
        ->and($rows[$active->username]['permissions'])->toContain('gate.entry');
});

it('is open to holders of user.view only, and its CSV export is audited', function () {
    $owner = Make::account($this->entity, 'SUPER_ADMIN');
    $gate = Make::account($this->entity, 'GATE_OPERATOR');
    app(AuthContextBinder::class)->bind($owner);
    expect(AccessReviewPage::canAccess())->toBeFalse(); // nobody signed in on the web guard

    $this->actingAs($owner, 'web')->withSession(Make::webSession($owner));
    app(AuthContextBinder::class)->bind($owner);
    $this->get('/admin/access-review')->assertOk()->assertSee('Access review')->assertSee($gate->username);
    Livewire::test(AccessReviewPage::class)->set('show', 'all')->assertSee($owner->username)->callAction('csv')->assertFileDownloaded();
    expect(Make::system(fn () => AuditLog::query()->where('action', 'report.exported')->latest('occurred_at')->first()->meta['report']))->toBe('access_review');
});

it('keeps the access review from roles without user.view', function () {
    $gate = Make::account($this->entity, 'GATE_OPERATOR');
    $this->actingAs($gate, 'web')->withSession(Make::webSession($gate))->get('/admin/access-review')
        ->assertForbidden()->assertSee('You do not have access to this page');
});
