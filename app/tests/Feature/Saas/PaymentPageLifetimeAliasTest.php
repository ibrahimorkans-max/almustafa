<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Saas\CustomerOnboarding;
use App\Domain\Saas\PaymentPage;
use App\Domain\Saas\PlatformStats;
use App\Filament\Pages\Platform\PlatformOverview;
use App\Filament\Pages\SubscriptionPage;
use App\Filament\Resources\Platform\CustomerResource\Pages\ManageCustomers;
use App\Filament\Resources\Platform\PlanResource\Pages\ManagePlans;
use App\Models\Customer;
use App\Models\LoginAccount;
use App\Models\Plan;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;
use Tests\Support\Make;

function pplOpen(string $company, string $username, ?string $alias = null): array
{
    $opened = app(CustomerOnboarding::class)->open([
        'company' => $company, 'owner_name' => 'Owner '.$company, 'phone' => '03'.random_int(100000000, 999999999), 'alias' => $alias,
        'username' => $username, 'password' => 'Paddy-Season-2026!', 'status' => Customer::ACTIVE,
        'plan_id' => Plan::query()->where('code', 'standard')->value('id'),
    ], 'platform');
    $owner = Make::system(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->findOrFail($opened['owner']->id));
    Make::system(fn () => $owner->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save());

    return [$opened['customer'], Make::system(fn () => $owner->fresh(['role', 'person', 'entity']))];
}

function pplSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->platform = Make::platformAdmin(Make::entity('PLT'));
});

it('shows the payment page to all customers, to none, or only to the customers ticked', function () {
    [$a, $ownerA] = pplOpen('Alpha Mills', 'alpha.owner');
    [$b, $ownerB] = pplOpen('Beta Mills', 'beta.owner');

    pplSignIn($this, $ownerA);
    expect(SubscriptionPage::canAccess())->toBeTrue();   // the default: every customer
    $this->get(SubscriptionPage::getUrl())->assertOk();

    pplSignIn($this, $this->platform);
    Livewire::test(ManageCustomers::class)->callAction('paymentPage', ['mode' => 'none'])->assertHasNoActionErrors();
    expect(PaymentPage::mode())->toBe('none');
    pplSignIn($this, $ownerA);
    expect(SubscriptionPage::canAccess())->toBeFalse();
    $this->get(SubscriptionPage::getUrl())->assertForbidden();

    // Selected: hide it from Beta only (choosing customers switches to "selected").
    pplSignIn($this, $this->platform);
    Livewire::test(ManageCustomers::class)->callTableBulkAction('hidePayment', [$b])->assertNotified();
    expect(PaymentPage::mode())->toBe('selected')->and($b->fresh()->payment_page)->toBeFalse()->and($a->fresh()->payment_page)->toBeTrue();
    pplSignIn($this, $ownerA);
    expect(SubscriptionPage::canAccess())->toBeTrue();
    pplSignIn($this, $ownerB);
    expect(SubscriptionPage::canAccess())->toBeFalse()->and(SubscriptionPage::banner($ownerB))->toBeNull();
});

it('offers a lifetime price paid once, shows it to the customer, and keeps what was paid', function () {
    [$customer, $owner] = pplOpen('Gamma Mills', 'gamma.owner');
    $standard = Plan::query()->where('code', 'standard')->firstOrFail();

    pplSignIn($this, $this->platform);
    Livewire::test(ManagePlans::class)->callTableAction('edit', $standard, ['lifetime_price' => '750000'])->assertHasNoTableActionErrors();
    expect($standard->fresh()->price_paise_lifetime)->toBe(75_000_000);

    pplSignIn($this, $owner);
    $this->get(SubscriptionPage::getUrl())->assertOk()->assertSee('once, for lifetime access')->assertSee('Rs 7,50,000.00')->assertSee('Lifetime access');

    pplSignIn($this, $this->platform);
    Livewire::test(ManageCustomers::class)
        ->mountTableAction('lifetime', $customer)->assertTableActionDataSet(['paid' => '750000.00'])
        ->setTableActionData(['paid' => '700000', 'note' => 'Paid once in cash', 'cancel_open' => true])->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($customer->fresh()->isLifetime())->toBeTrue()->and($customer->fresh()->lifetime_paid_paise)->toBe(70_000_000);

    $stats = app(PlatformStats::class)->summary();
    expect($stats['customers']['lifetime'])->toBe(1)->and($stats['lifetime_paid_paise'])->toBe(70_000_000);
    $this->get(PlatformOverview::getUrl())->assertOk()->assertSee('Lifetime customers')->assertSee('Rs 7,00,000.00');

    pplSignIn($this, $owner);
    $this->get(SubscriptionPage::getUrl())->assertOk()->assertSee('paid once: Rs 7,00,000.00');
});

it('opens several accounts at once, each with an alias only the product owner sees', function () {
    $undoRepeater = Repeater::fake();
    pplSignIn($this, $this->platform);
    Livewire::test(ManageCustomers::class)->callAction('several', [
        'plan_id' => Plan::query()->where('code', 'standard')->value('id'), 'status' => Customer::TRIAL, 'trial_days' => 14,
        'accounts' => [
            ['alias' => 'Uncle Riaz', 'company' => 'Riaz Rice Mills', 'phone' => '03001234567', 'owner_name' => 'Riaz Ahmad', 'username' => 'riaz.owner', 'password' => 'Paddy-Season-2026!'],
            ['alias' => 'Lodhran 2', 'company' => 'Kamal Rice', 'phone' => '03011234567', 'owner_name' => 'Kamal Din', 'username' => 'kamal.owner', 'password' => 'Paddy-Season-2026!'],
        ],
    ])->assertHasNoActionErrors()->assertNotified('2 account(s) opened.');

    $riaz = Customer::query()->where('name', 'Riaz Rice Mills')->sole();
    expect($riaz->alias)->toBe('Uncle Riaz')->and($riaz->status)->toBe(Customer::TRIAL)
        ->and(Customer::query()->where('name', 'Kamal Rice')->sole()->alias)->toBe('Lodhran 2')
        ->and(Make::system(fn () => LoginAccount::query()->where('username', 'riaz.owner')->exists()))->toBeTrue();

    // The product owner finds it by its alias; the customer never sees it.
    Livewire::test(ManageCustomers::class)->searchTable('Uncle Riaz')->assertCanSeeTableRecords([$riaz])->assertSee('Uncle Riaz');
    $owner = Make::system(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->where('username', 'riaz.owner')->sole());
    Make::system(fn () => $owner->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now(), 'must_change_password' => false])->save());
    pplSignIn($this, Make::system(fn () => $owner->fresh(['role', 'person', 'entity'])));
    $this->get(SubscriptionPage::getUrl())->assertOk()->assertSee('Riaz Rice Mills')->assertDontSee('Uncle Riaz');
    $this->get('/admin')->assertOk()->assertDontSee('Uncle Riaz');

    // A taken username stops only its own row.
    pplSignIn($this, $this->platform);
    $undoRepeater = Repeater::fake();
    Livewire::test(ManageCustomers::class)->callAction('several', [
        'plan_id' => Plan::query()->where('code', 'standard')->value('id'), 'status' => Customer::ACTIVE,
        'accounts' => [
            ['alias' => '', 'company' => 'Again Riaz', 'phone' => '03001234500', 'owner_name' => 'X', 'username' => 'riaz.owner', 'password' => 'Paddy-Season-2026!'],
            ['alias' => '', 'company' => 'New One', 'phone' => '03001234501', 'owner_name' => 'Y', 'username' => 'new.owner', 'password' => 'Paddy-Season-2026!'],
        ],
    ])->assertHasNoActionErrors()->assertNotified('1 account(s) opened, 1 not opened.');
    $undoRepeater();
    expect(Customer::query()->where('name', 'New One')->exists())->toBeTrue()->and(Customer::query()->where('name', 'Again Riaz')->exists())->toBeFalse();
});
