<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Saas\Billing;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Saas\CustomerOnboarding;
use App\Domain\Saas\PlanLimits;
use App\Filament\Pages\SubscriptionPage;
use App\Filament\Resources\Platform\SaasInvoiceResource\Pages\ManageSaasInvoices;
use App\Models\Customer;
use App\Models\LoginAccount;
use App\Models\Plan;
use App\Models\SaasInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Make;

/** A paying customer (Standard plan, Rs 35,000) with its Owner, signed in with 2FA set up. */
function payingCustomer(string $company, string $username, string $status = Customer::ACTIVE): array
{
    $opened = app(CustomerOnboarding::class)->open([
        'company' => $company, 'owner_name' => 'Owner '.$company, 'phone' => '03'.random_int(100000000, 999999999),
        'username' => $username, 'password' => 'Paddy-Season-2026!', 'status' => $status,
        'plan_id' => Plan::query()->where('code', 'standard')->value('id'),
    ], 'platform');
    $owner = Make::system(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->findOrFail($opened['owner']->id));
    Make::system(fn () => $owner->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save());

    return [$opened['customer'], Make::system(fn () => $owner->fresh(['role', 'person', 'entity']))];
}

function billingSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

function freshAccess(): void
{
    app(CustomerAccess::class)->forget();
    app(PlanLimits::class)->forget();
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config([
        'ricemill.saas.billing.due_days' => 10, 'ricemill.saas.billing.grace_days' => 7, 'ricemill.saas.billing.suspend_after_days' => 0,
        'ricemill.saas.billing.bank_title' => 'Rice Mill ERP (Pvt) Ltd', 'ricemill.saas.billing.bank_iban' => 'PK00TEST0000000000000001',
    ]);
});

it('issues one monthly invoice to paying customers, never to a trial, and tells the Owner', function () {
    [$paying, $owner] = payingCustomer('Paying Mills', 'paying.owner');
    [$trial] = payingCustomer('Trial Mills', 'trial2.owner', Customer::TRIAL);

    $this->artisan('saas:invoices')->expectsOutputToContain('1 invoices issued')->assertSuccessful();
    $this->artisan('saas:invoices')->expectsOutputToContain('0 invoices issued')->assertSuccessful();

    $invoice = SaasInvoice::query()->where('customer_id', $paying->id)->sole();
    expect($invoice->number)->toBe('SUB-'.CarbonImmutable::now('Asia/Karachi')->year.'-0001')
        ->and($invoice->amount_paise)->toBe(3_500_000)
        ->and($invoice->description)->toBe('Standard plan, '.CarbonImmutable::now('Asia/Karachi')->format('F Y'))
        ->and($invoice->status)->toBe(SaasInvoice::OPEN)
        ->and(SaasInvoice::query()->where('customer_id', $trial->id)->exists())->toBeFalse()
        ->and(Make::system(fn () => DB::table('notifications')->where('notifiable_id', $owner->id)->where('data', 'like', '%'.$invoice->number.'%')->exists()))->toBeTrue();
});

it('shows a customer its invoices and bank details, takes its payment report, and keeps other customers out', function () {
    Storage::fake('local');
    [$customer, $owner] = payingCustomer('Report Mills', 'report.owner');
    [, $other] = payingCustomer('Other Mills', 'other.owner');
    $platform = Make::platformAdmin(Make::entity('PLT'));
    $invoice = app(Billing::class)->issueMonthly($customer, CarbonImmutable::now('Asia/Karachi'));

    billingSignIn($this, $owner);
    $this->get('/admin/subscription')->assertOk()->assertSee($invoice->number)->assertSee('PK00TEST0000000000000001')->assertSee('I&#039;ve paid', false);
    $this->get(route('filament.admin.saas.invoice', ['id' => $invoice->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(PdfRenderer::$lastHtml)->toContain($invoice->number)->toContain('Rs 35,000.00')->toContain('PK00TEST0000000000000001');

    Livewire::test(SubscriptionPage::class)
        ->callAction('reportPayment', ['transaction_id' => 'HBL-778899', 'transaction_id_again' => 'hbl-778899', 'paid_on' => now('Asia/Karachi')->toDateString(),
            'receipt' => UploadedFile::fake()->image('receipt.jpg')], ['invoice' => $invoice->id])
        ->assertHasNoActionErrors()->assertNotified();
    $invoice->refresh();
    expect($invoice->reported_reference)->toBe('HBL-778899')->and($invoice->receipt_path)->not->toBeNull()
        ->and($invoice->status)->toBe(SaasInvoice::OPEN)
        ->and(Make::system(fn () => DB::table('notifications')->where('notifiable_id', $platform->id)->pluck('data')->implode(' | ')))->toContain('HBL-778899');
    $this->get(route('filament.admin.saas.receipt', ['id' => $invoice->id]))->assertOk();

    // Another customer's Owner sees neither the invoice nor the receipt.
    billingSignIn($this, $other);
    $this->get(route('filament.admin.saas.invoice', ['id' => $invoice->id]))->assertNotFound();
    $this->get(route('filament.admin.saas.receipt', ['id' => $invoice->id]))->assertNotFound();
    $this->get('/admin/subscription')->assertDontSee($invoice->number);
    $this->get('/admin/platform/invoices')->assertForbidden();
});

it('reminds before and on the due date, makes an unpaid customer read-only after the grace days, and opens it again once paid', function () {
    [$customer, $owner] = payingCustomer('Late Mills', 'late.owner');
    $platform = Make::platformAdmin(Make::entity('PLT'));
    $invoice = app(Billing::class)->issueMonthly($customer, CarbonImmutable::now('Asia/Karachi'));
    $check = fn () => Make::system(fn () => app(Billing::class)->check());

    $this->travel(8)->days();
    expect($check())->toBe(["{$invoice->number}: due_soon reminder."]);
    $this->travel(2)->days();
    expect($check())->toBe(["{$invoice->number}: due reminder."])->and($check())->toBe([]);
    $this->travel(1)->days();
    expect($check())->toBe(["{$invoice->number}: overdue reminder."]);
    expect($customer->refresh()->status)->toBe(Customer::ACTIVE);   // still inside the grace days

    $this->travel(7)->days();
    expect($check())->toBe(["{$customer->code}: read-only, {$invoice->number} 8 days overdue."])
        ->and($customer->refresh()->status)->toBe(Customer::PAST_DUE);
    freshAccess();

    // Read-only: it can look, not record or change.
    expect($owner->hasPermission('deal.view'))->toBeTrue()
        ->and($owner->hasPermission('deal.create'))->toBeFalse()
        ->and($owner->hasPermission('gate.entry'))->toBeFalse()
        ->and($owner->hasPermission('master.manage'))->toBeFalse();
    billingSignIn($this, $owner);
    $this->get('/admin')->assertOk()->assertSee('Read-only: payment overdue.');

    // Paid: open again at once.
    billingSignIn($this, $platform);
    Livewire::test(ManageSaasInvoices::class)
        ->callTableAction('markPaid', $invoice, ['amount' => '30000', 'paid_on' => now('Asia/Karachi')->toDateString(), 'reference' => 'X1'])
        ->assertNotified('The amount received is less than the invoice. Record it when the full amount has arrived.');
    Livewire::test(ManageSaasInvoices::class)
        ->callTableAction('markPaid', $invoice, ['amount' => '35000.00', 'paid_on' => now('Asia/Karachi')->toDateString(), 'reference' => 'HBL-1'])
        ->assertNotified('Payment recorded.');
    expect($invoice->refresh()->status)->toBe(SaasInvoice::PAID)->and($customer->refresh()->status)->toBe(Customer::ACTIVE);
    freshAccess();
    expect($owner->hasPermission('deal.create'))->toBeTrue();
});

it('pauses a customer unpaid for long when that is switched on', function () {
    config(['ricemill.saas.billing.suspend_after_days' => 30]);
    [$customer] = payingCustomer('Very Late Mills', 'verylate.owner');
    app(Billing::class)->issueMonthly($customer, CarbonImmutable::now('Asia/Karachi'));

    $this->travel(41)->days();
    Make::system(fn () => app(Billing::class)->check());
    expect($customer->refresh()->status)->toBe(Customer::SUSPENDED);
});

it('lets only the platform admin issue other invoices and cancel them', function () {
    [$customer] = payingCustomer('Setup Mills', 'setup.owner');
    billingSignIn($this, Make::platformAdmin(Make::entity('PLT')));
    $this->get('/admin/platform/invoices')->assertOk();

    Livewire::test(ManageSaasInvoices::class)
        ->callAction('newInvoice', ['customer_id' => $customer->id, 'description' => 'Setup and training', 'amount' => '25000', 'due_days' => 5])
        ->assertHasNoActionErrors()->assertNotified('Invoice issued.');
    $invoice = SaasInvoice::query()->where('customer_id', $customer->id)->sole();
    expect($invoice->kind)->toBe(SaasInvoice::OTHER)->and($invoice->amount_paise)->toBe(2_500_000);

    Livewire::test(ManageSaasInvoices::class)->callTableAction('void', $invoice, ['reason' => 'Issued by mistake'])->assertNotified('Invoice cancelled.');
    expect($invoice->refresh()->status)->toBe(SaasInvoice::VOID)->and($invoice->void_reason)->toBe('Issued by mistake');
});
