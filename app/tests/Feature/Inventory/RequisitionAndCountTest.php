<?php

declare(strict_types=1);

use App\Domain\Approvals\ApprovalService;
use App\Domain\Inventory\RequisitionService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Production\ProductionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Alert;
use App\Models\ApprovalRequest;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\StockEntry;
use App\Models\Production\ProductionLog;
use App\Support\Values\Weight;
use Tests\Support\InventoryFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new InventoryFixtures(Make::entity('REQ'));
    $this->f->as($this->f->storeManager);
    $this->f->stock($this->f->paddy, $this->f->silo, '10000');
    $this->stock = app(StockLedger::class);
});

function openBatch(object $t)
{
    $t->f->as($t->f->supervisor);
    $batch = app(ProductionService::class)->open($t->f->paddy, openedAt: now()->subHour());

    return $batch;
}

it('issues paddy to a batch only after a different person approves', function () {
    $batch = openBatch($this);
    $requisitions = app(RequisitionService::class);
    $req = $requisitions->request($this->f->paddy, $this->f->silo, '4000', toBatch: $batch, purpose: 'Night shift');

    expect($req->number)->toStartWith('REQ/RQ/')->and($req->status)->toBe('requested');
    expect(fn () => $requisitions->approve($req))->toThrow(BusinessRuleViolation::class, 'own requisition');
    expect(fn () => $requisitions->issue($req))->toThrow(BusinessRuleViolation::class, 'requested');

    $this->f->as($this->f->supervisor2);
    $requisitions->approve($req, 'ok');
    $this->f->as($this->f->storeManager);
    $issued = $requisitions->issue($req);

    $log = ProductionLog::query()->where('requisition_id', $req->id)->sole();
    expect($issued->status)->toBe('issued')
        ->and($log->kind)->toBe('input')->and($log->quantity_kg)->toBe('4000.000')
        ->and($batch->fresh()->input_kg)->toBe('4000.000')
        ->and($this->stock->balance($this->f->paddy, $this->f->silo)->kg)->toBe('6000.000');
    expect(fn () => $requisitions->issue($issued))->toThrow(BusinessRuleViolation::class);
});

it('transfers between godowns against a requisition and refuses when stock is short', function () {
    $requisitions = app(RequisitionService::class);
    $this->f->as($this->f->supervisor);
    $big = $requisitions->request($this->f->paddy, $this->f->silo, '12000', toGodown: $this->f->store);
    $ok = $requisitions->request($this->f->paddy, $this->f->silo, '2500', toGodown: $this->f->store);
    $this->f->as($this->f->storeManager);
    $requisitions->approve($big);
    $requisitions->approve($ok);

    expect(fn () => $requisitions->issue($big))->toThrow(BusinessRuleViolation::class, 'Not enough');
    expect($big->fresh()->status)->toBe('approved');

    $requisitions->issue($ok);
    expect($this->stock->balance($this->f->paddy, $this->f->store)->kg)->toBe('2500.000')
        ->and(StockEntry::query()->where('source_id', $ok->id)->count())->toBe(2);
});

it('checks the destination of a requisition', function () {
    $requisitions = app(RequisitionService::class);
    $batch = openBatch($this);

    expect(fn () => $requisitions->request($this->f->paddy, $this->f->silo, '10'))->toThrow(BusinessRuleViolation::class, 'either');
    expect(fn () => $requisitions->request($this->f->paddy, $this->f->silo, '10', $batch, $this->f->store))->toThrow(BusinessRuleViolation::class, 'either');
    expect(fn () => $requisitions->request($this->f->rice, $this->f->silo, '10', $batch))->toThrow(BusinessRuleViolation::class, 'different paddy');
    expect(fn () => $requisitions->request($this->f->paddy, $this->f->silo, '10', toGodown: $this->f->silo))->toThrow(BusinessRuleViolation::class, 'same');

    $req = $requisitions->request($this->f->paddy, $this->f->silo, '10', $batch);
    $requisitions->cancel($req, 'not needed');
    expect($req->fresh()->status)->toBe(Requisition::CANCELLED);
});

it('books a count difference only after maker-checker approval, as found at counting time', function () {
    $count = app(StockCountService::class)->record($this->f->paddy, $this->f->silo, '9800', 'Monthly physical count');

    expect($count->system_kg)->toBe('10000.000')->and($count->difference_kg)->toBe('-200.000')
        ->and($count->status)->toBe('pending')
        ->and($this->stock->balance($this->f->paddy, $this->f->silo)->kg)->toBe('10000.000');

    // Stock keeps moving before the approval; the adjustment is still the -200 found.
    $this->stock->post($this->f->paddy, $this->f->silo, Weight::kg('-1000'), StockEntry::PRODUCTION_ISSUE);
    expect(fn () => app(StockCountService::class)->record($this->f->paddy, $this->f->silo, '8800'))
        ->toThrow(BusinessRuleViolation::class, 'still waiting');

    $request = ApprovalRequest::query()->findOrFail($count->approval_request_id);
    $approved = app(ApprovalService::class)->approve($request, $this->f->gate->owner);

    expect($approved->status)->toBe('executed')
        ->and($count->fresh()->status)->toBe('applied')
        ->and($this->stock->balance($this->f->paddy, $this->f->silo)->kg)->toBe('8800.000')
        ->and(StockEntry::query()->where('movement', 'adjustment')->sole()->quantity_kg)->toBe('-200.000');
});

it('raises a shrinkage alert when the count is well below the books', function () {
    app(StockCountService::class)->record($this->f->paddy, $this->f->silo, '9900'); // 1 % short > 0.5 %

    $alert = Alert::query()->sole();
    expect($alert->type)->toBe('shrinkage')->and($alert->data['shrinkage_bp'])->toBe(100);

    // Small differences and surpluses do not alert.
    $this->f->as($this->f->gate->owner);
    app(ApprovalService::class)->reject(ApprovalRequest::query()->sole(), $this->f->gate->owner2, 'recount');
    $this->f->as($this->f->storeManager);
    app(StockCountService::class)->record($this->f->paddy, $this->f->silo, '9970');
    expect(Alert::query()->count())->toBe(1);
});

it('marks a count rejected when its adjustment is rejected, and applies a matching count at once', function () {
    $count = app(StockCountService::class)->record($this->f->paddy, $this->f->silo, '10100');
    app(ApprovalService::class)->reject(ApprovalRequest::query()->findOrFail($count->approval_request_id), $this->f->gate->owner, 'Recount the north bay');

    expect($count->fresh()->status)->toBe('rejected')
        ->and($this->stock->balance($this->f->paddy, $this->f->silo)->kg)->toBe('10000.000');

    $match = app(StockCountService::class)->record($this->f->paddy, $this->f->silo, '10000');
    expect($match->status)->toBe('applied')->and($match->approval_request_id)->toBeNull();
});

it('does not let the counter approve their own adjustment', function () {
    $count = app(StockCountService::class)->record($this->f->paddy, $this->f->silo, '9990');
    $request = ApprovalRequest::query()->findOrFail($count->approval_request_id);

    expect(app(ApprovalService::class)->canDecide($this->f->storeManager, $request))->toBeFalse()
        ->and(app(ApprovalService::class)->canDecide($this->f->gate->owner, $request))->toBeTrue();
});

it('books a count at once when the person counting may approve their own adjustment', function () {
    // The Owner's request is applied inside submit(); recording the count must not fail as stale.
    $this->f->as($this->f->gate->owner);
    $count = app(StockCountService::class)->record($this->f->paddy, $this->f->silo, '9900')->fresh();

    expect($count->status)->toBe('applied')
        ->and($count->approval_request_id)->not->toBeNull()
        ->and($this->stock->balance($this->f->paddy, $this->f->silo)->kg)->toBe('9900.000');
});
