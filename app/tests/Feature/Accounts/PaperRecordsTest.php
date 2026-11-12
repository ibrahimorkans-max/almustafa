<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\PaperRecords;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\PaperRecordResource;
use App\Filament\Resources\Accounts\PaperRecordResource\Pages\ListPaperRecords;
use App\Filament\Resources\Accounts\PaperRecordResource\Pages\ViewPaperRecord;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\Accounts\PaperRecord;
use App\Models\AuthSession;
use App\Models\Masters\Party;
use App\Models\Masters\Vehicle;
use App\Models\PartyMessage;
use App\Support\Values\Weight;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('OLD'));
    $this->f->as($this->f->owner);
    $this->paper = app(PaperRecords::class);
    $this->chart = app(ChartOfAccounts::class);
    $this->books = app(Books::class);
});

function signInOld(object $t): void
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $web = Make::webSession($t->f->owner);
    $t->actingAs($t->f->owner, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($t->f->owner, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
}

it('brings in opening balances of parties, cash and stock against one "Opening balances" account, without messaging parties', function () {
    $seller = $this->f->inv->gate->seller;
    $messages = PartyMessage::query()->count();

    $owed = $this->paper->record(['kind' => 'opening_party', 'date' => '2025-06-30', 'party_id' => $seller->id, 'amount' => '480000', 'side' => 'payable', 'book_ref' => 'Khata, page 40']);
    $cash = $this->paper->record(['kind' => 'opening_ledger', 'date' => '2025-06-30', 'account_id' => $this->chart->system('cash')->id, 'amount' => '85000', 'side' => 'dr']);
    $stock = $this->paper->record(['kind' => 'opening_stock', 'date' => '2025-06-30', 'product_id' => $this->f->inv->paddy->id, 'godown_id' => $this->f->inv->silo->id,
        'quantity_kg' => '20000', 'amount' => '1500000']);

    expect($owed->voucher->type)->toBe('opening')->and($owed->voucher->voucher_date->toDateString())->toBe('2025-06-30')
        ->and($this->books->balance($this->chart->partyLedger($seller)))->toBe(-48_000_000)
        ->and($this->books->balance($this->chart->system('cash')))->toBe(8_500_000)
        ->and($this->books->balance($this->chart->system('opening_stock')))->toBe(150_000_000)
        ->and($this->books->balance($this->chart->system('opening_balance')))->toBe(48_000_000 - 8_500_000 - 150_000_000)
        ->and($stock->stockEntry->movement)->toBe('opening')
        ->and(app(StockLedger::class)->balance($this->f->inv->paddy, $this->f->inv->silo)->compare(Weight::kg('20000')))->toBeGreaterThanOrEqual(0)
        ->and($cash->voucher->narration)->toStartWith('From paper:')
        ->and(PartyMessage::query()->count())->toBe($messages);
});

it('posts past purchases, sales, payments, receipts, expenses and journals on their paper dates', function () {
    $seller = $this->f->inv->gate->seller;
    $buyer = Party::query()->create(['name' => 'Haji Aslam Rice Dealers', 'is_customer' => true]);
    $ledger = fn (Party $p) => $this->chart->partyLedger($p);

    // 12,000 kg at Rs 3,200 a maund = Rs 960,000, worked out as on the paper bill; the paddy goes into stock.
    $buy = $this->paper->record(['kind' => 'purchase', 'date' => '2025-07-05', 'party_id' => $seller->id, 'paper_number' => '112',
        'product_id' => $this->f->inv->paddy->id, 'godown_id' => $this->f->inv->silo->id, 'quantity_kg' => '12000', 'rate_per_maund' => '3200']);
    expect($buy->amount_paise)->toBe(96_000_000)->and($buy->voucher->type)->toBe('purchase')
        ->and($buy->stockEntry->quantity_kg)->toBe('12000.000');

    $this->paper->record(['kind' => 'sale', 'date' => '2025-07-09', 'party_id' => $buyer->id, 'amount' => '640000']);
    $this->paper->record(['kind' => 'payment', 'date' => '2025-07-10', 'party_id' => $seller->id, 'amount' => '200000', 'cash_bank_id' => $this->f->bank->id]);
    $this->paper->record(['kind' => 'receipt', 'date' => '2025-07-12', 'party_id' => $buyer->id, 'amount' => '150000']);
    $this->paper->record(['kind' => 'expense', 'date' => '2025-07-12', 'account_id' => $this->chart->system('round_off')->id, 'amount' => '3500']);
    $this->paper->record(['kind' => 'journal', 'date' => '2025-07-15', 'account_id' => $ledger($seller)->id,
        'credit_account_id' => $this->chart->system('brokerage')->id, 'amount' => '5000', 'description' => 'Brokerage charged to supplier']);

    expect($this->books->balance($ledger($seller)))->toBe(-96_000_000 + 20_000_000 + 500_000)
        ->and($this->books->balance($ledger($buyer)))->toBe(64_000_000 - 15_000_000)
        ->and($this->books->balance($this->f->bank))->toBe(-20_000_000)
        ->and($this->books->balance($this->chart->system('cash')))->toBe(15_000_000 - 350_000)
        ->and($this->books->balance($this->chart->system('sales_rice')))->toBe(-64_000_000);

    // The same paper number on the same day is not entered twice.
    expect(fn () => $this->paper->record(['kind' => 'purchase', 'date' => '2025-07-05', 'party_id' => $seller->id, 'paper_number' => '112', 'amount' => '1000']))
        ->toThrow(BusinessRuleViolation::class, 'already entered');
});

it('keeps old weighbridge slips and register notes as written, and refuses future dates and missing amounts', function () {
    $slip = $this->paper->record(['kind' => 'weighment', 'date' => '05/07/2025', 'vehicle' => 'LHR-1234', 'gross_kg' => '32500', 'tare_kg' => '12500',
        'party_id' => $this->f->inv->gate->seller->id]);
    expect($slip->voucher_id)->toBeNull()->and($slip->quantity_kg)->toBe('20000.000')->and($slip->details['net_kg'])->toBe('20000.000');

    $note = $this->paper->record(['kind' => 'note', 'date' => '2025-07-20', 'description' => 'Moisture 14%, broken 3%']);
    expect($note->voucher_id)->toBeNull();

    expect(fn () => $this->paper->record(['kind' => 'note', 'date' => now()->addDays(3)->toDateString(), 'description' => 'x']))->toThrow(BusinessRuleViolation::class, 'future')
        ->and(fn () => $this->paper->record(['kind' => 'payment', 'date' => '2025-07-01', 'party_id' => $this->f->inv->gate->seller->id]))->toThrow(BusinessRuleViolation::class, 'amount');
});

it('imports a spreadsheet row by row: adds missing parties, reports bad rows, and cancels by reversing', function () {
    $csv = implode("\n", [
        // Only the columns needed, in any order.
        'kind,date,register_page,paper_number,party,amount,side,account,cash_bank',
        'opening_party,30/06/2025,"Khata 2025, page 12",,New Farmer Ali,250000,receivable,,',
        'receipt,12/07/2025,Cash book,,New Farmer Ali,100000,,,Cash',
        'payment,13/07/2025,Cash book,,New Farmer Ali,5000,,,Unknown Bank',
        'purchase,32/13/2025,,,New Farmer Ali,1000,,,',
    ]);

    $result = $this->paper->import($csv);
    expect($result['entered'])->toBe(2)
        ->and(array_column($result['errors'], 'row'))->toBe([4, 5])
        ->and($result['errors'][0]['error'])->toContain('Unknown Bank');

    $party = Party::query()->where('name', 'New Farmer Ali')->sole();
    expect($party->is_customer)->toBeTrue()
        ->and($this->books->balance($this->chart->partyLedger($party)))->toBe(15_000_000)
        ->and(PaperRecord::query()->where('import_batch', $result['batch'])->count())->toBe(2);

    $receipt = PaperRecord::query()->where('kind', 'receipt')->sole();
    $this->paper->cancel($receipt, 'Entered twice on the page');
    expect($receipt->fresh()->isCancelled())->toBeTrue()
        ->and($this->books->balance($this->chart->partyLedger($party)))->toBe(25_000_000)
        ->and(fn () => $this->paper->cancel($receipt->fresh(), 'Again please'))->toThrow(BusinessRuleViolation::class, 'already cancelled');
});

it('keeps the vehicle, party and payment details of each record, and adds the vehicle and driver to the lists', function () {
    $seller = Party::query()->create(['name' => 'Rana Paddy Traders', 'is_vendor' => true, 'city' => 'Kahror Pakka']);

    $buy = $this->paper->record(['kind' => 'purchase', 'date' => '2025-07-05', 'party_id' => $seller->id, 'amount' => '960000',
        'party_phone' => '0301-7654321', 'party_cnic' => '36302-1234567-1', 'party_address' => 'Grain Market', 'party_city' => 'Multan',
        'vehicle' => 'bkx 22 9876', 'vehicle_type' => 'truck', 'driver_name' => 'Muhammad Akram', 'driver_phone' => '03021112233', 'bags' => '300', 'bilty_no' => 'B-778']);

    // Party: blanks filled from the paper, what was already saved (the city) kept.
    $seller->refresh();
    expect($seller->phone)->toBe('+923017654321')->and($seller->cnic)->toBe('3630212345671')->and($seller->address)->toBe('Grain Market')
        ->and($seller->city)->toBe('Kahror Pakka')
        ->and($buy->details['party'])->toMatchArray(['name' => 'Rana Paddy Traders', 'phone' => '0301-7654321', 'cnic' => '*********5671', 'city' => 'Kahror Pakka'])
        ->and($buy->details)->toMatchArray(['vehicle' => 'BKX-22-9876', 'vehicle_type' => 'truck', 'driver_name' => 'Muhammad Akram',
            'driver_phone' => '0302-1112233', 'bags' => '300', 'bilty_no' => 'B-778'])
        ->and($buy->voucher->narration)->toContain('vehicle BKX-22-9876');

    $vehicle = Vehicle::query()->where('registration_no', 'BKX229876')->sole();
    expect($vehicle->owner_party_id)->toBe($seller->id)->and($vehicle->defaultDriver->name)->toBe('Muhammad Akram')
        ->and($buy->details['vehicle_id'])->toBe($vehicle->id);

    // The same vehicle again is found, not added twice.
    $this->paper->record(['kind' => 'weighment', 'date' => '2025-07-05', 'vehicle' => 'BKX-22-9876', 'gross_kg' => '32500', 'tare_kg' => '12500']);
    expect(Vehicle::query()->where('registration_no', 'BKX229876')->count())->toBe(1);

    // Payment: mode, cheque no., cheque date, party's bank, who paid; the mode must match cash or bank.
    $pay = $this->paper->record(['kind' => 'payment', 'date' => '2025-07-11', 'party_id' => $seller->id, 'amount' => '300000', 'cash_bank_id' => $this->f->bank->id,
        'payment_mode' => 'cheque', 'reference' => '00456789', 'cheque_date' => '2025-07-11', 'party_bank' => 'HBL Kahror Pakka', 'handled_by' => 'Munshi Iqbal']);
    expect($pay->details)->toMatchArray(['payment_mode' => 'cheque', 'reference' => '00456789', 'cheque_date' => '2025-07-11',
        'party_bank' => 'HBL Kahror Pakka', 'handled_by' => 'Munshi Iqbal'])
        ->and($pay->voucher->narration)->toContain('Cheque 00456789');

    expect(fn () => $this->paper->record(['kind' => 'payment', 'date' => '2025-07-11', 'party_id' => $seller->id, 'amount' => '1000', 'payment_mode' => 'cheque', 'cash_bank_id' => $this->f->bank->id]))
        ->toThrow(BusinessRuleViolation::class, 'enter its number')
        ->and(fn () => $this->paper->record(['kind' => 'payment', 'date' => '2025-07-11', 'party_id' => $seller->id, 'amount' => '1000', 'payment_mode' => 'ibft', 'reference' => 'T1']))
        ->toThrow(BusinessRuleViolation::class, 'choose the bank account')
        ->and(fn () => $this->paper->record(['kind' => 'payment', 'date' => '2025-07-11', 'party_id' => $seller->id, 'amount' => '1000', 'payment_mode' => 'cash', 'cash_bank_id' => $this->f->bank->id]))
        ->toThrow(BusinessRuleViolation::class, 'choose Cash')
        ->and(fn () => $this->paper->record(['kind' => 'purchase', 'date' => '2025-07-11', 'party_id' => $seller->id, 'amount' => '1000', 'party_cnic' => '123']))
        ->toThrow(BusinessRuleViolation::class, 'is not a CNIC');
});

it('imports vehicle, party and payment details from the spreadsheet; the template has every column on every row', function () {
    $lines = array_map('str_getcsv', explode("\n", trim(ltrim(PaperRecords::template(), "\xEF\xBB\xBF"))));
    expect($lines[0])->toBe(PaperRecords::COLUMNS);
    foreach ($lines as $line) {
        expect($line)->toHaveCount(count(PaperRecords::COLUMNS));
    }

    $row = fn (array $values) => implode(',', array_map(fn (string $c) => '"'.($values[$c] ?? '').'"', PaperRecords::COLUMNS));
    $csv = implode("\n", [implode(',', PaperRecords::COLUMNS),
        $row(['kind' => 'sale', 'date' => '09/07/2025', 'party' => 'Haji Aslam', 'party_phone' => '0300-1234567', 'party_city' => 'Lodhran', 'amount' => '640000',
            'vehicle' => 'LES-4455', 'driver_name' => 'Ghulam Rasool', 'bags' => '160']),
        $row(['kind' => 'receipt', 'date' => '12/07/2025', 'party' => 'Haji Aslam', 'amount' => '150000', 'cash_bank' => $this->f->bank->name,
            'payment_mode' => 'online', 'reference' => 'TXN 88213', 'party_bank' => 'MCB Lodhran']),
    ]);
    $result = $this->paper->import($csv);
    expect($result['errors'])->toBe([])->and($result['entered'])->toBe(2);

    $party = Party::query()->where('name', 'Haji Aslam')->sole();
    expect($party->phone)->toBe('+923001234567')->and($party->city)->toBe('Lodhran')
        ->and(PaperRecord::query()->where('kind', 'sale')->sole()->details)->toMatchArray(['vehicle' => 'LES-4455', 'driver_name' => 'Ghulam Rasool', 'bags' => '160'])
        ->and(PaperRecord::query()->where('kind', 'receipt')->sole()->details)->toMatchArray(['payment_mode' => 'ibft', 'reference' => 'TXN 88213', 'party_bank' => 'MCB Lodhran']);
});

it('is a screen under Accounts: add a record, download the template, open and cancel it', function () {
    signInOld($this);
    $this->get(PaperRecordResource::getUrl())->assertOk()->assertSee('Old records (from paper)');

    Livewire::test(ListPaperRecords::class)
        ->callAction('add', ['kind' => 'opening_party', 'date' => '2025-06-30', 'party_id' => $this->f->inv->gate->seller->id, 'side' => 'receivable', 'amount' => '1000'])
        ->assertHasNoActionErrors()->assertNotified()
        ->callAction('template')->assertFileDownloaded('old-records-template.csv')
        ->callAction('add', ['kind' => 'payment', 'date' => '2025-07-11', 'party_id' => $this->f->inv->gate->seller->id, 'amount' => '5000',
            'cash_bank_id' => $this->f->bank->id, 'payment_mode' => 'cheque', 'reference' => '778899', 'cheque_date' => '2025-07-11', 'handled_by' => 'Munshi'])
        ->assertHasNoActionErrors()
        ->callAction('add', ['kind' => 'purchase', 'date' => '2025-07-05', 'party_id' => $this->f->inv->gate->seller->id, 'amount' => '9000',
            'vehicle' => 'TKR-777', 'driver_name' => 'Akram', 'bags' => 20, 'party_city' => 'Lodhran'])
        ->assertHasNoActionErrors();
    expect(PaperRecord::query()->where('kind', 'payment')->sole()->details)->toMatchArray(['payment_mode' => 'cheque', 'reference' => '778899'])
        ->and(PaperRecord::query()->where('kind', 'purchase')->sole()->details)->toMatchArray(['vehicle' => 'TKR-777', 'driver_name' => 'Akram', 'bags' => '20']);
    PaperRecord::query()->whereIn('kind', ['payment', 'purchase'])->get()->each(fn (PaperRecord $r) => $this->paper->cancel($r, 'Test entries only'));

    $record = PaperRecord::query()->where('kind', 'opening_party')->sole();
    $this->get(PaperRecordResource::getUrl('view', ['record' => $record]))->assertOk()->assertSee('Opening balance — party');
    Livewire::test(ViewPaperRecord::class, ['record' => $record->getRouteKey()])
        ->callAction('cancel', ['reason' => 'Wrong party'])->assertNotified('Record cancelled');
    expect($record->fresh()->isCancelled())->toBeTrue();
});
