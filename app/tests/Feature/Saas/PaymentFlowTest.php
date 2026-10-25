<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Saas\Billing;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Saas\CustomerOnboarding;
use App\Domain\Saas\PaymentGate;
use App\Filament\Pages\PaymentRequiredPage;
use App\Filament\Pages\SubscriptionPage;
use App\Filament\Resources\Platform\CustomerResource\Pages\ManageCustomers;
use App\Filament\Resources\Platform\PaymentMethodResource\Pages\ManagePaymentMethods;
use App\Filament\Resources\Platform\SaasInvoiceResource\Pages\ManageSaasInvoices;
use App\Models\Customer;
use App\Models\LoginAccount;
use App\Models\Plan;
use App\Models\SaasInvoice;
use App\Models\SaasPaymentMethod;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use Tests\Support\Make;

/** A customer with its Owner (2FA set up) and one staff login. */
function payCustomer(string $company, string $username, string $status = Customer::ACTIVE, int $trialDays = 14): array
{
    $opened = app(CustomerOnboarding::class)->open([
        'company' => $company, 'owner_name' => 'Owner '.$company, 'phone' => '03'.random_int(100000000, 999999999),
        'username' => $username, 'password' => 'Paddy-Season-2026!', 'status' => $status, 'trial_days' => $trialDays,
        'plan_id' => Plan::query()->where('code', 'standard')->value('id'),
    ], 'platform');
    Make::system(fn () => $opened['owner']->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now(), 'must_change_password' => false])->save());
    $owner = Make::system(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->findOrFail($opened['owner']->id));
    $staff = Make::account($opened['mill'], 'GATE_OPERATOR');

    return [$opened['customer'], $owner, $staff];
}

function paySignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    app(CustomerAccess::class)->forget();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

/** A Livewire update request naming the given component. */
function livewireCall(string $componentClass): Request
{
    $request = Request::create('/livewire/update', 'POST', ['components' => [[
        'snapshot' => json_encode(['memo' => ['name' => app(ComponentRegistry::class)->getName($componentClass)]]),
    ]]]);

    return $request;
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('local');
});

it('sends a customer whose trial ended to the payment page, and nowhere else, until it is paid', function () {
    [$customer, $owner, $staff] = payCustomer('Ended Mills', 'ended.owner', Customer::TRIAL, 3);
    $platform = Make::platformAdmin(Make::entity('PLT'));
    Make::system(fn () => SaasPaymentMethod::query()->create(['name' => 'JazzCash', 'kind' => 'wallet', 'account_title' => 'Rice Mill ERP',
        'account_number' => '0300-1234567', 'qr_text' => 'jazzcash://pay?to=03001234567', 'active' => true]));
    $this->travel(4)->days();

    paySignIn($this, $owner);
    $this->get('/admin')->assertRedirect(PaymentRequiredPage::getUrl());
    $this->get('/admin/gate')->assertRedirect(PaymentRequiredPage::getUrl());
    $this->get(SubscriptionPage::getUrl())->assertRedirect(PaymentRequiredPage::getUrl());
    expect($owner->hasPermission('gate.entry'))->toBeFalse()->and($owner->hasPermission('master.view'))->toBeFalse();

    // The page: why, its first invoice (made now), the ways to pay with a QR, and "I've paid".
    $this->get(PaymentRequiredPage::getUrl())->assertOk()->assertSee('Your free trial has ended')
        ->assertSee('JazzCash')->assertSee('0300-1234567')->assertSee('data:image/svg+xml;base64', false)->assertSee('Rs 35,000.00');
    $invoice = SaasInvoice::query()->where('customer_id', $customer->id)->sole();

    // Only the payment page's own Livewire calls go through.
    expect(PaymentGate::allows(livewireCall(PaymentRequiredPage::class)))->toBeTrue()
        ->and(PaymentGate::allows(livewireCall(ManageCustomers::class)))->toBeFalse();

    Livewire::test(PaymentRequiredPage::class)
        ->callAction('reportPayment', ['transaction_id' => 'jc-55001', 'transaction_id_again' => 'JC-55001', 'paid_on' => now('Asia/Karachi')->toDateString()], ['invoice' => $invoice->id])
        ->assertNotified();
    expect($invoice->refresh()->reported_reference)->toBe('JC-55001');

    // The notification bell stays closed: its notices hold the company's data.
    expect(PaymentGate::allows(livewireCall(DatabaseNotifications::class)))->toBeFalse();

    // Staff see only the message: no invoice, no amount, no way to pay.
    paySignIn($this, $staff);
    $this->get('/admin')->assertRedirect(PaymentRequiredPage::getUrl());
    $this->get(PaymentRequiredPage::getUrl())->assertOk()->assertSee('owner has paid')
        ->assertDontSee('Report a payment')->assertDontSee($invoice->number)->assertDontSee('Rs 35,000.00')->assertDontSee('JazzCash');
    $this->get(route('filament.admin.saas.invoice', ['id' => $invoice->id]))->assertNotFound();

    // Paid: open again.
    paySignIn($this, $platform);
    Livewire::test(ManageSaasInvoices::class)
        ->callTableAction('markPaid', $invoice, ['amount' => '35000', 'paid_on' => now('Asia/Karachi')->toDateString(), 'reference' => 'JC-55001'])
        ->assertNotified('Payment recorded.');
    expect($customer->refresh()->status)->toBe(Customer::ACTIVE)->and($customer->trial_ends_at)->toBeNull();
    paySignIn($this, $owner);
    $this->get('/admin')->assertOk();
    $this->get(PaymentRequiredPage::getUrl())->assertForbidden();   // nothing to pay any more
});

it('saves the transaction ID exactly once, checks it was typed the same twice, and shows it on the card and to the platform', function () {
    [$customer, $owner] = payCustomer('Txn Mills', 'txn.owner');
    [$other, $otherOwner] = payCustomer('Other Txn', 'othertxn.owner');
    $method = Make::system(fn () => SaasPaymentMethod::query()->create(['name' => 'Meezan Bank', 'kind' => 'bank', 'account_title' => 'MillNexa', 'active' => true]));
    $billing = app(Billing::class);
    $invoice = $billing->issueMonthly($customer, CarbonImmutable::now('Asia/Karachi'));
    $otherInvoice = $billing->issueMonthly($other, CarbonImmutable::now('Asia/Karachi'));
    $today = now('Asia/Karachi')->toDateString();

    paySignIn($this, $owner);
    // Typed differently the second time: refused, nothing saved.
    Livewire::test(SubscriptionPage::class)
        ->callAction('reportPayment', ['method_id' => $method->id, 'transaction_id' => 'FT2611 2233', 'transaction_id_again' => 'FT26112234', 'amount' => '35000', 'paid_on' => $today], ['invoice' => $invoice->id])
        ->assertHasActionErrors(['transaction_id_again']);
    expect($invoice->refresh()->reported_reference)->toBeNull();

    // The same ID, spaces and small letters as typed: saved cleaned, with how, how much and when.
    Livewire::test(SubscriptionPage::class)
        ->callAction('reportPayment', ['method_id' => $method->id, 'transaction_id' => 'ft2611 2233', 'transaction_id_again' => 'FT26112233', 'amount' => '35000', 'paid_on' => $today], ['invoice' => $invoice->id])
        ->assertHasNoActionErrors();
    $invoice->refresh();
    expect($invoice->reported_reference)->toBe('FT26112233')
        ->and($invoice->reported_method_id)->toBe($method->id)
        ->and($invoice->reported_amount_paise)->toBe(3_500_000);
    $this->get(SubscriptionPage::getUrl())->assertOk()->assertSee('FT26112233')->assertSee('Being checked')->assertSee('Meezan Bank')
        ->assertSee('Correct the transaction');

    // Another company cannot report the same transaction for its own invoice.
    paySignIn($this, $otherOwner);
    Livewire::test(SubscriptionPage::class)
        ->callAction('reportPayment', ['method_id' => $method->id, 'transaction_id' => 'FT26112233', 'transaction_id_again' => 'FT26112233', 'amount' => '35000', 'paid_on' => $today], ['invoice' => $otherInvoice->id])
        ->assertNotified('This transaction ID was already given for invoice '.$invoice->number.'. Check it on your receipt.');
    expect($otherInvoice->refresh()->reported_reference)->toBeNull();

    // The platform finds it, sees it, and pays the invoice with it; the card then shows the payment received.
    paySignIn($this, Make::platformAdmin(Make::entity('PLT')));
    Livewire::test(ManageSaasInvoices::class)->searchTable('FT26112233')->assertCanSeeTableRecords([$invoice])->assertCanNotSeeTableRecords([$otherInvoice])
        ->callTableAction('markPaid', $invoice, ['amount' => '35000', 'paid_on' => $today, 'reference' => 'ft26112233'])->assertNotified('Payment recorded.');
    expect($invoice->refresh()->payment_reference)->toBe('FT26112233');
    paySignIn($this, $owner);
    $this->get(SubscriptionPage::getUrl())->assertSee('Payment received')->assertSee('FT26112233');
});

it('keeps a blocked company signed in but hides all of its data, and closes the app to it', function () {
    [$customer, $owner, $staff] = payCustomer('Blocked Mills', 'blocked.owner');
    Make::system(fn () => $customer->update(['status' => Customer::SUSPENDED]));

    paySignIn($this, $owner);
    $this->get('/admin')->assertOk()->assertSee('Your account is blocked.')->assertSee('All of your company&#039;s data is hidden', false);
    $this->get('/admin/gate')->assertRedirect('/admin');                 // every data screen sends back to the start page
    $this->get('/admin/masters/parties')->assertRedirect('/admin');
    $this->get(PaymentRequiredPage::getUrl())->assertRedirect('/admin');  // not the payment page
    $this->get(SubscriptionPage::getUrl())->assertOk();                     // the Owner can still pay
    expect($owner->hasPermission('gate.entry'))->toBeFalse()->and($owner->hasPermission('master.view'))->toBeFalse()
        ->and(PaymentGate::allowsBlocked(livewireCall(DatabaseNotifications::class)))->toBeFalse()
        ->and(PaymentGate::allowsBlocked(livewireCall(ManageCustomers::class)))->toBeFalse()
        ->and(app(CustomerAccess::class)->blocks($owner))->toBeTrue();   // the app and API refuse it

    paySignIn($this, $staff);
    $this->get('/admin')->assertOk()->assertSee('Your account is blocked.');
    $this->get(SubscriptionPage::getUrl())->assertForbidden();   // My subscription is for Owners only
});

it('gives lifetime access: never billed, never read-only, and can be taken back', function () {
    [$customer, $owner] = payCustomer('Founding Mills', 'founding.owner');
    $platform = Make::platformAdmin(Make::entity('PLT'));
    $open = app(Billing::class)->issueMonthly($customer, CarbonImmutable::now('Asia/Karachi'));

    paySignIn($this, $platform);
    Livewire::test(ManageCustomers::class)
        ->callTableAction('lifetime', $customer, ['note' => 'Founding customer', 'cancel_open' => true])
        ->assertNotified('Founding Mills has lifetime access.');
    $customer->refresh();
    expect($customer->isLifetime())->toBeTrue()->and($customer->status)->toBe(Customer::ACTIVE)
        ->and($open->refresh()->status)->toBe(SaasInvoice::VOID)
        ->and(app(Billing::class)->issueMonthly($customer, CarbonImmutable::now('Asia/Karachi')->addMonth()))->toBeNull();

    // A one-off invoice left unpaid never makes it read-only.
    $other = app(Billing::class)->issue($customer, 'Training', 1_000_000, dueDays: 1);
    $this->travel(30)->days();
    Make::system(fn () => app(Billing::class)->check());
    expect($customer->refresh()->status)->toBe(Customer::ACTIVE)->and($other->refresh()->status)->toBe(SaasInvoice::OPEN);

    paySignIn($this, $owner);
    $this->get(SubscriptionPage::getUrl())->assertOk()->assertSee('Lifetime access')->assertDontSee('Ways to pay');

    paySignIn($this, $platform);
    Livewire::test(ManageCustomers::class)->callTableAction('endLifetime', $customer, ['note' => 'Agreement ended'])->assertNotified();
    expect($customer->refresh()->isLifetime())->toBeFalse();
});

it('lets only the platform admin set up ways to pay with a QR picture, shown to customers', function () {
    [, $owner] = payCustomer('Payer Mills', 'payer.owner');
    paySignIn($this, $owner);
    $this->get('/admin/platform/payment-methods')->assertForbidden();

    paySignIn($this, Make::platformAdmin(Make::entity('PLT')));
    Livewire::test(ManagePaymentMethods::class)
        ->callAction('create', ['name' => 'Meezan Bank', 'kind' => 'bank', 'account_title' => 'Rice Mill ERP (Pvt) Ltd',
            'iban' => 'PK36MEZN0001234567890123', 'active' => true, 'sort' => 1,
            'qr_upload' => [UploadedFile::fake()->image('qr.png', 300, 300)]])
        ->assertHasNoActionErrors();
    $method = SaasPaymentMethod::query()->where('name', 'Meezan Bank')->sole();
    expect($method->qr_path)->toStartWith('saas-qr/')->toEndWith('.png')->and($method->hasQr())->toBeTrue();

    paySignIn($this, $owner);
    $this->get(SubscriptionPage::getUrl())->assertOk()->assertSee('Meezan Bank')->assertSee('PK36MEZN0001234567890123')
        ->assertSee('data:image/png;base64', false);
});
