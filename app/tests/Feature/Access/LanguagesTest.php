<?php

declare(strict_types=1);

use App\Models\LoginAccount;
use App\Support\Locales;
use Tests\Support\Make;

it('shows the sign-in page in English until another language is chosen, then right to left in that language', function () {
    $this->get('/admin/login')->assertOk()->assertSee('lang="en"', false)->assertSee('dir="ltr"', false)->assertSee('English');

    $this->get('/language/ur')->assertRedirect()->assertCookie('locale');
    $this->withCookie('locale', 'ur')->get('/admin/login')->assertOk()
        ->assertSee('lang="ur"', false)->assertSee('dir="rtl"', false)->assertSee('سائن ان کریں')->assertSee('پاس ورڈ');
});

it('refuses a language the system does not have', function () {
    $this->get('/language/fr')->assertNotFound();
    $this->get('/language/xx')->assertNotFound();
});

it('remembers the language a person chooses on every device and shows the menu in it', function () {
    $entity = Make::entity('LNG');
    $admin = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);

    $this->actingAs($admin, 'web')->withSession(Make::webSession($admin))->get('/language/ps')->assertRedirect();
    $stored = Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->findOrFail($admin->id));
    expect($stored->locale)->toBe('ps');

    // Another session of the same person (no language in it yet): the person's own choice is used.
    $this->flushSession();
    $html = $this->actingAs($stored, 'web')->withSession(Make::webSession($admin))->get('/admin')->assertOk()->getContent();
    // The menu: its pages and its groups (a group is matched to its pages by the translated name).
    expect($html)->toContain('lang="ps"', 'dir="rtl"', __('Dashboard', [], 'ps'), __('Security', [], 'ps'))
        ->and(__('Dashboard', [], 'ps'))->not->toBe('Dashboard')
        ->and(__('Security', [], 'ps'))->not->toBe('Security');
});

it('shows Urdu, Pashto and Sindhi for every form error', function () {
    foreach (['ur', 'ps', 'sd'] as $locale) {
        app()->setLocale($locale);
        $message = validator(['name' => ''], ['name' => 'required'])->errors()->first('name');
        expect($message)->not->toContain('field is required')->toContain('name');
    }
});

it('keeps every :placeholder of the English text in each translation', function () {
    foreach (array_keys(Locales::all()) as $locale) {
        if ($locale === 'en') {
            continue;
        }
        $lines = json_decode((string) file_get_contents(lang_path("$locale.json")), true);
        expect($lines)->toBeArray()->not->toBeEmpty();
        foreach ($lines as $english => $translated) {
            preg_match_all('/:[a-z_][a-z0-9_]*/i', (string) $english, $wanted);
            foreach ($wanted[0] as $placeholder) {
                expect($translated)->toContain($placeholder);
            }
        }
    }
});

it('translates fixed option lists but never a word that has no translation', function () {
    app()->setLocale('ur');

    expect(Locales::options(['yes' => 'Yes', 'x' => 'Zz-not-a-word', 'group' => ['a' => 'No']]))
        ->toBe(['yes' => 'ہاں', 'x' => 'Zz-not-a-word', 'group' => ['a' => 'نہیں']]);
});
