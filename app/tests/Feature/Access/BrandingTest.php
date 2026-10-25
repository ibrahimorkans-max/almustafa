<?php

declare(strict_types=1);

use App\Domain\Ops\Preflight;
use App\Models\Masters\TaxRate;
use Illuminate\Support\Facades\DB;
use Tests\Support\Make;

it('shows the MillNexa product branding and the narrow, collapsible sidebar in the admin panel', function () {
    $entity = Make::entity('BRD');
    $owner = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);

    $html = $this->actingAs($owner, 'web')->withSession(Make::webSession($owner))->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('--sidebar-width: 15rem', '--collapsed-sidebar-width: 4.5rem', 'images/product/millnexa-logo.png',
        'images/product/millnexa-logo-dark.png', 'images/product/favicon-32.png', 'images/product/apple-touch-icon.png', 'manifest.webmanifest', 'MillNexa')
        ->not->toContain('images/brand/logo-wide.png');
});

it('brands the public pages with the product, never one mill', function () {
    $this->get('/admin/login')->assertOk()->assertSee('images/product/millnexa-logo.png', false)->assertSee('MillNexa');
    // The public document check serves every customer: the product's name, not one mill's address or phone.
    $this->get('/verify')->assertOk()->assertSee('MillNexa')->assertSee('Smart Rice Mill Management')->assertSee('دستاویز کی تصدیق')
        ->assertDontSee('0300-6834588');
});

it('gives every new mill the two default sales-tax rates', function () {
    $entity = Make::entity('TXD');

    expect(Make::system(fn () => TaxRate::query()->withoutGlobalScopes()->where('entity_id', $entity->id)->pluck('exempt', 'name')->all()))
        ->toBe(['Exempt (Sixth Schedule)' => true, 'Standard 18%' => false]);
});

it('checks the printing and branding files before a deployment', function () {
    $checks = collect(app(Preflight::class)->run())->keyBy('check');

    expect($checks['Logo and icon files present']['ok'])->toBeTrue()
        ->and($checks['PDF fonts present (Urdu: Lateef)']['ok'])->toBeTrue()
        ->and($checks['PHP extension mbstring']['ok'])->toBeTrue();
});

it('expects pending migrations during a deploy but blocks them afterwards', function () {
    $migrations = glob(database_path('migrations/*.php')) ?: [];
    DB::table('migrations')->where('migration', basename(end($migrations), '.php'))->delete(); // pretend the newest one is still to run

    $before = collect(app(Preflight::class)->run(beforeMigrate: true))->keyBy('check');
    $after = collect(app(Preflight::class)->run())->keyBy('check');

    expect($before['Migrations to run in this deploy'])->toMatchArray(['ok' => true, 'detail' => '1 pending (run next)'])
        ->and($before)->not->toHaveKey('All migrations have run')
        ->and($after['All migrations have run'])->toMatchArray(['ok' => false, 'level' => 'error']);
});
