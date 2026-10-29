<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\ExpenseCategories;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Claims\ClaimService;
use App\Domain\Files\FileStore;
use App\Filament\Resources\Accounts\ExpenseResource\Pages\CreateExpense;
use App\Filament\Resources\Accounts\VoucherResource\Pages\ListVouchers;
use App\Filament\Resources\Claims\ClaimResource;
use App\Filament\Resources\Procurement\SaudaResource\Pages\CreateSauda;
use App\Filament\Resources\Sales\SalesSaudaResource;
use App\Filament\Resources\Sales\SalesSaudaResource\Pages\ListSalesSaudas;
use App\Filament\Support\ProductSelect;
use App\Models\Accounts\Expense;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\Voucher;
use App\Models\LoginAccount;
use App\Models\Masters\Product;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

/*
 * Phase 1 of the October 2026 bug list: product pickers, sales sauda visibility, the expense
 * Bank choice, contra entries both ways and claim evidence. (The stock count fix is tested in
 * Inventory/RequisitionAndCountTest.)
 */
beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('FIX1'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function fix1SignIn(object $t, LoginAccount $account): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

it('lets the sales desk see sales saudas with sales rights alone, but not purchase deals', function () {
    $sale = $this->f->sauda;
    $storeman = Make::account($this->f->entity, 'INVENTORY_MANAGER', ['mfa' => true]); // sales.view, no deal.view
    expect($storeman->hasPermission('sales.view'))->toBeTrue()->and($storeman->hasPermission('deal.view'))->toBeFalse();

    fix1SignIn($this, $storeman);
    expect(SalesSaudaResource::canViewAny())->toBeTrue()->and(SalesSaudaResource::canCreate())->toBeFalse();
    Livewire::test(ListSalesSaudas::class)->assertCanSeeTableRecords([$sale])->assertCanNotSeeTableRecords([$this->f->acc->sauda]);
    $this->get('/admin/sales/saudas/'.$sale->id)->assertOk();
    $this->get('/admin/procurement/saudas')->assertForbidden();
});

it('offers only active products of the right kind, labelled with their code, in the product pickers', function () {
    fix1SignIn($this, $this->f->acc->owner);
    $paddy = $this->f->inv->paddy;
    $old = Product::query()->create(['code' => 'PDY-OLD', 'name' => 'Old paddy', 'category' => 'paddy', 'status' => 'inactive']);

    $options = ProductSelect::options(['paddy']);
    expect($options)->toHaveKey($paddy->id)
        ->and($options[$paddy->id])->toBe($paddy->code.' — '.$paddy->name)
        ->and($options)->not->toHaveKey($old->id)
        ->and($options)->not->toHaveKey($this->f->inv->rice->id)
        // A record already pointing at a product made inactive still shows its name.
        ->and(ProductSelect::label($old->id))->toBe('PDY-OLD — Old paddy (inactive)');

    Livewire::test(CreateSauda::class)->assertFormFieldExists('product_id');
});

it('says so when a mill has no product of the kind yet', function () {
    fix1SignIn($this, $this->f->acc->owner);
    Product::query()->where('category', 'paddy')->update(['status' => 'inactive']);

    Livewire::test(CreateSauda::class)->assertSee('This mill has no active paddy products yet.');
});

it('records an expense paid from the bank, and lists only bank accounts for it', function () {
    fix1SignIn($this, $this->f->acc->accountant);
    if (ExpenseCategory::query()->doesntExist()) {
        app(ExpenseCategories::class)->installDefaults();
    }
    $category = ExpenseCategory::query()->where('kind', 'expense')->where('status', 'active')->orderBy('code')->firstOrFail();

    Livewire::test(CreateExpense::class)
        ->fillForm(['via' => 'bank'])
        ->assertFormFieldExists('cash_ledger_id', fn ($field) => array_keys($field->getOptions()) === [$this->f->acc->bank->id])
        ->fillForm([
            'expense_category_id' => $category->id, 'entry_date' => CarbonImmutable::now('Asia/Karachi')->toDateString(), 'amount' => '2500',
            'description' => 'Bank charges', 'via' => 'bank', 'cash_ledger_id' => $this->f->acc->bank->id, 'post' => true,
        ])->call('create')->assertHasNoFormErrors();

    expect(Expense::query()->sole()->cash_ledger_id)->toBe($this->f->acc->bank->id);
});

it('moves money cash to bank and bank to cash as contra entries', function () {
    fix1SignIn($this, $this->f->acc->accountant);
    $cash = app(ChartOfAccounts::class)->system('cash');
    $bank = $this->f->acc->bank;
    $books = app(Books::class);
    [$cash0, $bank0] = [$books->balance($cash), $books->balance($bank)];

    Livewire::test(ListVouchers::class)
        ->callAction('cash_to_bank', ['from' => $cash->id, 'to' => $bank->id, 'amount' => '10000', 'date' => now()->toDateString()])
        ->assertHasNoActionErrors();
    Livewire::test(ListVouchers::class)
        ->callAction('bank_to_cash', ['from' => $bank->id, 'to' => $cash->id, 'amount' => '2500', 'date' => now()->toDateString()])
        ->assertHasNoActionErrors();

    expect(Voucher::query()->where('type', 'contra')->count())->toBe(2)
        ->and($books->balance($bank) - $bank0)->toBe(7_500_00)
        ->and($books->balance($cash) - $cash0)->toBe(-7_500_00);

    // The deposit form lists only cash on the "from" side.
    Livewire::test(ListVouchers::class)->mountAction('cash_to_bank')
        ->assertActionDataSet(['to' => $bank->id]);
});

it('opens claim evidence from the claim and shows the bill claimed against', function () {
    fix1SignIn($this, $this->f->acc->owner);
    $entry = $this->f->acc->receiveLot();
    fix1SignIn($this, $this->f->acc->accountant); // the fixtures above leave the mill
    $bill = app(PurchaseBillService::class)->post($this->f->acc->billFor($entry));
    fix1SignIn($this, $this->f->acc->owner);
    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    $sha = app(FileStore::class)->put($pdf)->sha256;
    $claim = app(ClaimService::class)->raise($bill, 'quality', 'Moisture higher than agreed on arrival', 1000_00, [$sha]);

    $links = ClaimResource::evidenceLinks($claim);
    expect($links)->toContain(route('filament.admin.claim-evidence', ['id' => $claim->id, 'sha256' => $sha]))->toContain('PDF 1');

    $this->get(route('filament.admin.claim-evidence', ['id' => $claim->id, 'sha256' => $sha]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    // A hash that is not on this claim is not served.
    $this->get(route('filament.admin.claim-evidence', ['id' => $claim->id, 'sha256' => str_repeat('a', 64)]))->assertNotFound();

    $this->get(ClaimResource::getUrl('view', ['record' => $claim]))->assertOk()
        ->assertSee('Document claimed against')->assertSee($bill->number)->assertSee('PDF 1');

    // Someone without claim rights cannot open it.
    $gate = Make::account($this->f->entity, 'GATE_OPERATOR', ['mfa' => true]);
    fix1SignIn($this, $gate);
    $this->get(route('filament.admin.claim-evidence', ['id' => $claim->id, 'sha256' => $sha]))->assertForbidden();
});
