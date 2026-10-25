<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Saas\CustomerOnboarding;
use App\Filament\Pages\BrandingPage;
use App\Models\Entity;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Make;

function brandingSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('local');
});

it('prints a new customer\'s mill as itself, never with the first installation\'s name or logo', function () {
    $opened = app(CustomerOnboarding::class)->open([
        'company' => 'Noor Rice Traders', 'mill_name' => 'Noor Rice Mill', 'city' => 'Sahiwal', 'owner_name' => 'Noor',
        'phone' => '03004445556', 'username' => 'noor.owner', 'password' => 'Paddy-Season-2026!',
    ], 'signup');
    $brand = PdfRenderer::brand($opened['mill']);
    expect($brand['name'])->toBe('Noor Rice Mill')
        ->and($brand['name_ur'])->toBe('')
        ->and($brand['logo'])->toBeNull()
        ->and($brand['phone'])->toBe('03004445556')
        ->and($brand['address'])->toBe('Sahiwal');

    // The first installation's mills keep the configured brand where they set nothing.
    $first = PdfRenderer::brand(Make::entity('AMX'));
    expect($first['name_ur'])->toBe((string) config('ricemill.brand.name_ur'))->and($first['logo'])->not->toBeNull();
});

it('lets the mill\'s Super Admin set its name, logo, colours, terms, slip note and bank, and prints them', function () {
    $mill = Make::entity('BRD');
    $sa = Make::account($mill, 'SUPER_ADMIN', ['mfa' => true]);
    brandingSignIn($this, $sa);
    $this->get(BrandingPage::getUrl())->assertOk()->assertSee('Print &amp; branding', false);

    Livewire::test(BrandingPage::class)
        ->fillForm([
            'legal_name' => 'Barkat Rice Mills (Pvt) Ltd', 'name_ur' => 'برکت رائس ملز', 'tagline' => 'Quality rice since 1985',
            'email' => 'info@barkat.example', 'color_primary' => '#123456', 'color_accent' => '#abcdef',
            'invoice_terms' => 'Payment within 15 days.', 'slip_note' => 'Keep this slip until payment.', 'footer_note' => 'Thank you for your business',
            'bank_title' => 'Barkat Rice Mills', 'bank_name' => 'HBL Lodhran', 'bank_iban' => 'PK36HABB0000001123456702',
            'logo' => [UploadedFile::fake()->image('logo.png', 600, 200)],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved. Every document printed from now on uses these details.');

    $mill = Make::system(fn () => Entity::query()->findOrFail($mill->id));
    expect($mill->logo_path)->toStartWith("branding/{$mill->id}/logo-")->toEndWith('.png')
        ->and(Storage::disk('local')->exists($mill->logo_path))->toBeTrue();
    $brand = PdfRenderer::brand($mill);
    expect($brand['name'])->toBe('Barkat Rice Mills (Pvt) Ltd')
        ->and($brand['green'])->toBe('#123456')
        ->and($brand['logo'])->toBe(Storage::disk('local')->path($mill->logo_path))
        ->and($brand['bank'])->toBe(['Account title' => 'Barkat Rice Mills', 'Bank' => 'HBL Lodhran', 'IBAN' => 'PK36HABB0000001123456702']);

    $this->get(route('filament.admin.branding.preview'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(PdfRenderer::$lastHtml)->toContain('Barkat Rice Mills (Pvt) Ltd')->toContain('Quality rice since 1985')
        ->toContain('info@barkat.example')->toContain('Payment within 15 days.')->toContain('PK36HABB0000001123456702')
        ->toContain('Keep this slip until payment.');

    // A new logo replaces the old file; "remove" takes it away.
    $old = $mill->logo_path;
    $this->travel(2)->seconds();
    Livewire::test(BrandingPage::class)->fillForm(['logo' => [UploadedFile::fake()->image('new.jpg', 400, 120)]])->call('save')->assertHasNoFormErrors();
    $new = Make::system(fn () => Entity::query()->findOrFail($mill->id))->logo_path;
    expect($new)->toEndWith('.jpg')->and(Storage::disk('local')->exists($old))->toBeFalse();
    Livewire::test(BrandingPage::class)->fillForm(['remove_logo' => true])->call('save');
    expect(Make::system(fn () => Entity::query()->findOrFail($mill->id))->logo_path)->toBeNull()
        ->and(Storage::disk('local')->exists($new))->toBeFalse();
});

it('refuses a file that is not really a picture, and keeps staff without the right out', function () {
    $mill = Make::entity('BRF');
    brandingSignIn($this, Make::account($mill, 'SUPER_ADMIN', ['mfa' => true]));
    Livewire::test(BrandingPage::class)
        ->fillForm(['logo' => [UploadedFile::fake()->createWithContent('logo.png', 'not a picture at all')]])
        ->call('save');
    expect(Make::system(fn () => Entity::query()->findOrFail($mill->id))->logo_path)->toBeNull();

    brandingSignIn($this, Make::account($mill, 'GATE_OPERATOR'));
    $this->get(BrandingPage::getUrl())->assertForbidden();
    $this->get(route('filament.admin.branding.preview'))->assertForbidden();
});
