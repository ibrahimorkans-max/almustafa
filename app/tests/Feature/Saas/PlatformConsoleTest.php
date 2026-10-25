<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Saas\Billing;
use App\Domain\Saas\CustomerOnboarding;
use App\Domain\Saas\PlanLimits;
use App\Domain\Saas\PlatformStats;
use App\Filament\Pages\Platform\PlatformOverview;
use App\Filament\Resources\Platform\CustomerResource;
use App\Filament\Resources\Platform\CustomerResource\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\LoginAccount;
use App\Models\Plan;
use App\Models\SaasInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;

function consoleCustomer(string $company, string $username, string $plan, string $status, int $trialDays = 14): Customer
{
    return app(CustomerOnboarding::class)->open([
        'company' => $company, 'owner_name' => 'Owner '.$company, 'phone' => '03'.random_int(100000000, 999999999),
        'username' => $username, 'password' => 'Paddy-Season-2026!', 'status' => $status, 'trial_days' => $trialDays,
        'plan_id' => Plan::query()->where('code', $plan)->value('id'),
    ], 'platform')['customer'];
}

function consoleSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('sums the business: customers by state, recurring income, invoiced, collected, outstanding and overdue', function () {
    $standard = consoleCustomer('Standard Mills', 'std.owner', 'standard', Customer::ACTIVE);   // Rs 35,000
    $pro = consoleCustomer('Pro Mills', 'pro.owner', 'pro', Customer::ACTIVE);                  // Rs 60,000
    consoleCustomer('Trial Mills', 'trial.c.owner', 'basic', Customer::TRIAL, 3);
    $platform = Make::platformAdmin(Make::entity('PLT'));

    $billing = app(Billing::class);
    $a = $billing->issueMonthly($standard, CarbonImmutable::now('Asia/Karachi'));
    $b = $billing->issueMonthly($pro, CarbonImmutable::now('Asia/Karachi'));
    $billing->markPaid($b, $platform, 6_000_000, now('Asia/Karachi')->toDateString(), 'HBL-9', null);

    $before = Make::system(fn () => Customer::query()->count());
    $s = app(PlatformStats::class)->summary();
    expect($s['customers']['active'])->toBeGreaterThanOrEqual(2)
        ->and($s['customers']['trial'])->toBeGreaterThanOrEqual(1)
        ->and(array_sum($s['customers']))->toBe($before)
        ->and($s['mrr_paise'])->toBeGreaterThanOrEqual(9_500_000)
        ->and($s['invoiced_paise'])->toBe(9_500_000)
        ->and($s['collected_paise'])->toBe(6_000_000)
        ->and($s['outstanding_paise'])->toBe(3_500_000)
        ->and($s['overdue_paise'])->toBe(0);

    $this->travel(12)->days();
    expect(app(PlatformStats::class)->summary()['overdue_paise'])->toBe(3_500_000)
        ->and(app(PlatformStats::class)->overdue()->pluck('id')->all())->toBe([$a->id]);
});

it('lists what needs attention: payments to check, trials ending, customers near a limit', function () {
    $trial = consoleCustomer('Ending Mills', 'ending.owner', 'basic', Customer::TRIAL, 2);
    $busy = consoleCustomer('Busy Mills', 'busy.owner', 'basic', Customer::ACTIVE);
    Make::system(fn () => Plan::query()->where('code', 'basic')->update(['max_users' => 1]));   // its Owner fills it
    app(PlanLimits::class)->forget();
    $invoice = app(Billing::class)->issueMonthly($busy, CarbonImmutable::now('Asia/Karachi'));
    Make::system(fn () => $invoice->update(['reported_at' => now(), 'reported_reference' => 'MCB-55', 'reported_paid_on' => now()->toDateString()]));

    $stats = app(PlatformStats::class);
    expect($stats->trialsEnding()->pluck('id'))->toContain($trial->id)
        ->and($stats->reported()->pluck('number')->all())->toBe([$invoice->number])
        ->and(collect($stats->nearLimits())->first(fn ($r) => $r['customer']->id === $busy->id && $r['label'] === 'Staff users'))->toMatchArray(['used' => 1, 'max' => 1]);
});

/** @return array<string, list<string>> the menu the signed-in account sees: group => item labels */
function menuOf(object $t, LoginAccount $a): array
{
    consoleSignIn($t, $a);
    $t->get('/admin/subscription');   // any panel request, so the menu is built for this account
    $menu = [];
    foreach (Filament::getNavigation() as $group) {
        $items = array_values(array_map(fn ($i) => $i->getLabel(), array_filter($group->getItems()->all(), fn ($i) => $i->isVisible())));
        if ($items !== []) {
            $menu[(string) ($group->getLabel() ?? '')] = $items;
        }
    }

    return $menu;
}

it('shows the Platform menu to the product owner only, and the product owner no mill screens', function () {
    $mill = Make::entity('MNU');
    $owner = Make::account($mill, 'OWNER', ['mfa' => true]);
    $sa = Make::account($mill, 'SUPER_ADMIN', ['mfa' => true]);
    $platform = Make::platformAdmin(Make::entity('PLT'));

    foreach ([$owner, $sa] as $account) {
        expect(menuOf($this, $account))->not->toHaveKey('Platform');
    }
    $menu = menuOf($this, $platform);
    expect($menu['Platform'] ?? [])->toBe(['Overview', 'Customers', 'Invoices', 'Payment methods', 'Plans'])
        ->and(array_keys($menu))->not->toContain('Procurement')->not->toContain('Gate & weighbridge')
        ->not->toContain('Stock & production')->not->toContain('Accounts')->not->toContain('Masters')
        ->and($platform->hasPermission('gate.entry'))->toBeFalse()
        ->and($platform->hasPermission('user.manage'))->toBeTrue();

    // Its start page is the platform overview.
    $this->get('/admin')->assertRedirect(PlatformOverview::getUrl());
});

it('shows the overview and each customer\'s page to the platform admin only', function () {
    $customer = consoleCustomer('Page Mills', 'page.owner', 'standard', Customer::ACTIVE);
    $invoice = app(Billing::class)->issueMonthly($customer, CarbonImmutable::now('Asia/Karachi'));
    $owner = Make::system(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->where('username', 'page.owner')->sole());
    Make::system(fn () => $owner->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save());

    consoleSignIn($this, Make::system(fn () => $owner->fresh(['role', 'person', 'entity'])));
    $this->get(PlatformOverview::getUrl())->assertForbidden();
    $this->get(CustomerResource::getUrl('view', ['record' => $customer]))->assertForbidden();

    consoleSignIn($this, Make::platformAdmin(Make::entity('PLT')));
    $this->get(PlatformOverview::getUrl())->assertOk()
        ->assertSee('Monthly recurring income')->assertSee('Page Mills')->assertSee('Trials ending within 7 days');
    $this->get(CustomerResource::getUrl('view', ['record' => $customer]))->assertOk()
        ->assertSee('Page Mills')->assertSee('Standard')->assertSee($invoice->number)->assertSee('page.owner')
        ->assertSee('added by the platform');

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->callAction('newInvoice', ['description' => 'Setup and training', 'amount' => '20000', 'due_days' => 7])
        ->assertNotified('Invoice issued.');
    expect(SaasInvoice::query()->where('customer_id', $customer->id)->where('kind', SaasInvoice::OTHER)->sole()->amount_paise)->toBe(2_000_000);
});
