<?php

declare(strict_types=1);

use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockEntry;
use App\Support\Values\Weight;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new InventoryFixtures(Make::entity('STK'));
    $this->f->as($this->f->storeManager);
    $this->ledger = app(StockLedger::class);
});

it('keeps the balance equal to the sum of the ledger', function () {
    $this->f->stock($this->f->paddy, $this->f->silo, '10000');
    $this->ledger->post($this->f->paddy, $this->f->silo, Weight::kg('-2500.5'), StockEntry::PRODUCTION_ISSUE);

    expect($this->ledger->balance($this->f->paddy, $this->f->silo)->kg)->toBe('7499.500')
        ->and(StockEntry::query()->count())->toBe(2)
        ->and($this->ledger->verify())->toBe([])
        ->and(AuditLog::query()->where('action', 'stock.receipt')->exists())->toBeTrue();
});

it('refuses to take out more than the godown holds', function () {
    $this->f->stock($this->f->paddy, $this->f->silo, '1000');

    expect(fn () => $this->ledger->post($this->f->paddy, $this->f->silo, Weight::kg('-1000.001'), StockEntry::PRODUCTION_ISSUE))
        ->toThrow(BusinessRuleViolation::class, 'Not enough');
    expect($this->ledger->balance($this->f->paddy, $this->f->silo)->kg)->toBe('1000.000')
        ->and(StockEntry::query()->count())->toBe(1);
});

it('refuses zero movements and non-stock products', function () {
    expect(fn () => $this->ledger->post($this->f->paddy, $this->f->silo, Weight::zero(), StockEntry::RECEIPT))
        ->toThrow(BusinessRuleViolation::class, 'zero');

    $this->f->as($this->f->gate->owner);
    $this->f->rice->forceFill(['is_stock_item' => false])->save();
    expect(fn () => $this->ledger->post($this->f->rice->fresh(), $this->f->store, Weight::kg('1'), StockEntry::RECEIPT))
        ->toThrow(BusinessRuleViolation::class, 'not a stock item');
});

it('transfers between godowns as two ledger rows in one transaction', function () {
    $this->f->stock($this->f->rice, $this->f->store, '500');
    [$out, $in] = $this->ledger->transfer($this->f->rice, $this->f->store, $this->f->silo, Weight::kg('200'));

    expect($out->quantity_kg)->toBe('-200.000')->and($in->quantity_kg)->toBe('200.000')
        ->and($this->ledger->balance($this->f->rice, $this->f->store)->kg)->toBe('300.000')
        ->and($this->ledger->balance($this->f->rice, $this->f->silo)->kg)->toBe('200.000');

    expect(fn () => $this->ledger->transfer($this->f->rice, $this->f->store, $this->f->silo, Weight::kg('301')))
        ->toThrow(BusinessRuleViolation::class);
    expect($this->ledger->balance($this->f->rice, $this->f->silo)->kg)->toBe('200.000');
});

it('corrects by a reversal row, once', function () {
    $entry = $this->f->stock($this->f->bran, $this->f->store, '120');
    $reversal = $this->ledger->reverse($entry, 'Booked to the wrong godown');

    expect($reversal->quantity_kg)->toBe('-120.000')->and($reversal->reverses_id)->toBe($entry->id)
        ->and($this->ledger->balance($this->f->bran, $this->f->store)->kg)->toBe('0.000');
    expect(fn () => $this->ledger->reverse($entry, 'again'))->toThrow(BusinessRuleViolation::class, 'already reversed');
});

it('rejects UPDATE and DELETE on the ledger at the database level', function () {
    $entry = $this->f->stock($this->f->paddy, $this->f->silo, '100');

    expect(fn () => DB::table('stock_ledger')->where('id', $entry->id)->update(['quantity_kg' => '1000']))->toThrow(QueryException::class);
    expect(fn () => DB::table('stock_ledger')->where('id', $entry->id)->delete())->toThrow(QueryException::class);
});

it('detects a balance that no longer matches the ledger', function () {
    $this->f->stock($this->f->paddy, $this->f->silo, '100');
    DB::table('stock_balances')->update(['quantity_kg' => '900']);

    $mismatches = $this->ledger->verify();
    expect($mismatches)->toHaveCount(1)
        ->and($mismatches[0])->toMatchArray(['balance_kg' => '900.000', 'ledger_kg' => '100.000']);

    Make::reset();
    expect(Artisan::call('stock:verify'))->toBe(1);
});

it('keeps each mill’s stock to itself', function () {
    $this->f->stock($this->f->paddy, $this->f->silo, '100');
    $other = Make::entity('OTH');
    $outsider = Make::account($other, 'OWNER');
    $this->f->as($outsider);

    expect(StockEntry::query()->count())->toBe(0)
        ->and(StockBalance::query()->count())->toBe(0);
});
