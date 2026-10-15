<?php

declare(strict_types=1);

use App\Domain\Gate\GateService;
use App\Domain\Sales\SalesBillService;
use App\Filament\Pages\ArchiveSearch;
use App\Filament\Resources\Claims\ClaimResource\Pages\CreateClaim;
use App\Filament\Resources\Claims\ClaimResource\Pages\ViewClaim;
use App\Filament\Resources\Pricing\PriceListResource\Pages\CreatePriceList;
use App\Filament\Resources\Pricing\PriceListResource\Pages\ViewPriceList;
use App\Filament\Resources\Sales\SalesBillResource\Pages\ViewSalesBill;
use App\Models\Accounts\ProductCost;
use App\Models\ApprovalRequest;
use App\Models\Claims\Claim;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Pricing\PriceList;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('ADM7'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function signInP7(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

dataset('phase 7 screens', [
    'claims' => ['/admin/claims', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR']],
    'price lists' => ['/admin/price-lists', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT']],
    'markups' => ['/admin/markups', ['OWNER', 'SUPER_ADMIN']],
    'archive' => ['/admin/archive', ['OWNER', 'SUPER_ADMIN', 'ACCOUNTANT']],
]);

it('opens claims, pricing and archive screens only for the right roles', function (string $path, array $allowed) {
    foreach (['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR'] as $role) {
        $account = Make::account($this->f->entity, $role, ['mfa' => true]);
        $status = $this->actingAs($account, 'web')->withSession(Make::webSession($account))->get($path)->getStatusCode();
        app('auth')->forgetGuards();
        $this->flushSession();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$role} → {$path}");
    }
})->with('phase 7 screens');

it('runs a claim from the screens: raise with a photo, approve, post the credit note', function () {
    $entry = $this->f->loadAndWeigh($this->f->order());
    $this->f->as($this->f->acc->accountant);
    $invoice = app(SalesBillService::class)->post($this->f->billFor($entry));

    signInP7($this, $this->f->dispatcher);
    Livewire::test(CreateClaim::class)
        ->fillForm([
            'document_type' => 'sales_bill', 'document_id' => $invoice->id, 'type' => 'weight_shortage', 'amount' => '10552.50',
            'description' => 'Buyer weighed 250 kg less at their godown',
            'evidence' => [UploadedFile::fake()->image('wet-bags.jpg')],
        ])
        ->call('create')->assertHasNoFormErrors();
    $claim = Claim::query()->sole();
    expect($claim->claimed_paise)->toBe(1_055_250)->and($claim->evidence)->toHaveCount(1);
    Livewire::test(ViewClaim::class, ['record' => $claim->id])->assertActionHidden('approve');

    signInP7($this, $this->f->acc->owner);
    Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('approve', ['amount' => '10552.50', 'note' => null])->assertNotified('Settlement approved');
    signInP7($this, $this->f->acc->accountant);
    Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('settle')->assertNotified('Note posted');

    expect($claim->fresh()->status)->toBe('settled')->and($claim->fresh()->voucher_id)->not->toBeNull();
});

it('creates a price list and requests a rate change for approval', function () {
    signInP7($this, $this->f->acc->owner);
    Livewire::test(CreatePriceList::class)
        ->fillForm(['name' => 'Rice rates', 'valid_from' => now()->startOfMonth()->toDateString()])
        ->call('create')->assertHasNoFormErrors();
    $list = PriceList::query()->sole();

    Livewire::test(ViewPriceList::class, ['record' => $list->id])
        ->callAction('rate', ['product_id' => $this->f->inv->rice->id, 'rate' => '4200', 'reason' => 'Market rate'])
        ->assertNotified('Rate change sent for approval');
    expect(ApprovalRequest::query()->where('action', 'pricing.rate_change')->sole()->status)->toBe('pending');
});

it('hides cost and margin from roles without permission while every other figure stays identical', function () {
    Make::system(fn () => ProductCost::query()->create([
        'entity_id' => $this->f->entity->id, 'product_id' => $this->f->inv->rice->id, 'avg_paise_per_kg' => '3000.0000',
        'basis_kg' => '20000', 'source_type' => 'production_batch', 'created_at' => now(),
    ]));
    $entry = $this->f->loadAndWeigh($this->f->order());
    $this->f->as($this->f->acc->accountant);
    $invoice = app(SalesBillService::class)->post($this->f->billFor($entry));

    signInP7($this, $this->f->acc->accountant);
    $accountantView = Livewire::test(ViewSalesBill::class, ['record' => $invoice->id])
        ->assertSee('422,100.00')->assertSee('402,000.00')->assertDontSee('Cost and margin')->assertDontSee('1,200.00');

    signInP7($this, $this->f->acc->owner);
    Livewire::test(ViewSalesBill::class, ['record' => $invoice->id])
        ->assertSee('422,100.00')->assertSee('402,000.00')
        ->assertSee('Cost and margin')->assertSee('1,200.00')->assertSee('400.00')->assertSee('25%'); // per maund: cost Rs 30 × 40 kg
    expect($accountantView)->not->toBeNull();
});

it('finds archived records and restores one with a reason', function () {
    $this->f->as($this->f->dispatcher);
    $gate = app(GateService::class);
    $old = $gate->arrive(['registration_no' => 'MNA211234']);
    $gate->cancel($old, 'Driver left');
    DB::table('gate_entries')->where('id', $old->id)->update(['updated_at' => now()->subMonths(30)]);
    Make::reset();
    $this->artisan('archive:run')->assertSuccessful();

    signInP7($this, $this->f->acc->owner);
    Livewire::test(ArchiveSearch::class)->set('term', 'MNA2')->call('search')->assertSee($old->number)
        ->callAction('restore', ['reason' => 'Needed for a weight dispute in court'], ['type' => 'gate_entry', 'id' => $old->id])
        ->assertNotified('Restored');

    expect(GateEntry::query()->find($old->id)->archived_at)->toBeNull();
});
