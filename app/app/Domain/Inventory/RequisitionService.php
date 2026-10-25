<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Approvals\SelfApproval;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Production\ProductionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Inventory\Requisition;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Production\ProductionBatch;
use App\Support\Values\Weight;
use Illuminate\Support\Facades\DB;

/**
 * Stock leaves a godown only against an approved requisition: requested → approved by a
 * different person → issued (the stock moves). Destination is a production batch (paddy
 * fed to the mill) or another godown (transfer).
 */
final class RequisitionService
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly ProductionService $production,
        private readonly DocumentNumbers $numbers,
        private readonly ActorContext $actor,
    ) {}

    public function request(
        Product $product,
        Godown $from,
        string $quantityKg,
        ?ProductionBatch $toBatch = null,
        ?Godown $toGodown = null,
        ?string $purpose = null,
    ): Requisition {
        $this->require('requisition.create');
        if (($toBatch === null) === ($toGodown === null)) {
            throw new BusinessRuleViolation('Choose either a production batch or a destination godown.');
        }
        if (! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantityKg) || bccomp($quantityKg, '0', 3) <= 0) {
            throw new BusinessRuleViolation('Quantity must be a positive weight in kg.');
        }
        if ($toBatch !== null) {
            if (! $toBatch->isOpen()) {
                throw new BusinessRuleViolation("Batch {$toBatch->number} is closed.");
            }
            if ($toBatch->paddy_product_id !== $product->id) {
                throw new BusinessRuleViolation("Batch {$toBatch->number} mills a different paddy.");
            }
        }
        if ($toGodown !== null && $toGodown->id === $from->id) {
            throw new BusinessRuleViolation('Source and destination godown are the same.');
        }

        return DB::transaction(fn () => Requisition::query()->create([
            'number' => $this->numbers->next(DocumentNumbers::REQUISITION),
            'product_id' => $product->id,
            'from_godown_id' => $from->id,
            'to_godown_id' => $toGodown?->id,
            'to_batch_id' => $toBatch?->id,
            'quantity_kg' => Weight::kg($quantityKg)->kg,
            'purpose' => $purpose,
        ]));
    }

    public function approve(Requisition $requisition, ?string $note = null): Requisition
    {
        $this->require('requisition.approve');

        return DB::transaction(function () use ($requisition, $note): Requisition {
            $requisition = $this->lock($requisition, Requisition::REQUESTED);
            $this->assertNotRequester($requisition);
            $requisition->forceFill([
                'status' => Requisition::APPROVED,
                'approved_by' => $this->actor->accountId(),
                'approved_at' => now(),
                'decision_note' => $note,
            ])->save();

            return $requisition;
        });
    }

    public function reject(Requisition $requisition, string $note): Requisition
    {
        $this->require('requisition.approve');

        return DB::transaction(function () use ($requisition, $note): Requisition {
            $requisition = $this->lock($requisition, Requisition::REQUESTED);
            $this->assertNotRequester($requisition);
            $requisition->forceFill([
                'status' => Requisition::REJECTED,
                'approved_by' => $this->actor->accountId(),
                'approved_at' => now(),
                'decision_note' => $note,
            ])->save();

            return $requisition;
        });
    }

    /** The requester may withdraw a request that has not been issued yet. */
    public function cancel(Requisition $requisition, ?string $note = null): Requisition
    {
        return DB::transaction(function () use ($requisition, $note): Requisition {
            $requisition = $this->lock($requisition, Requisition::REQUESTED, Requisition::APPROVED);
            if ($requisition->created_by !== $this->actor->accountId() && ! ($this->actor->account()?->hasPermission('requisition.approve') ?? false)) {
                throw new BusinessRuleViolation('Only the requester or an approver can cancel this requisition.');
            }
            $requisition->forceFill(['status' => Requisition::CANCELLED, 'decision_note' => $note ?? $requisition->decision_note])->save();

            return $requisition;
        });
    }

    /** Moves the stock. Refuses if the godown does not hold enough. */
    public function issue(Requisition $requisition): Requisition
    {
        $this->require('requisition.create');

        return DB::transaction(function () use ($requisition): Requisition {
            $requisition = $this->lock($requisition, Requisition::APPROVED);

            if ($requisition->to_batch_id !== null) {
                $this->production->recordRequisitionInput(
                    $requisition->toBatch ?? throw new BusinessRuleViolation('Batch not found.'),
                    $requisition->fromGodown,
                    $requisition->quantity_kg,
                    $requisition->id,
                    "Requisition {$requisition->number}",
                );
            } else {
                $this->stock->transfer(
                    $requisition->product,
                    $requisition->fromGodown,
                    $requisition->toGodown ?? throw new BusinessRuleViolation('Destination godown not found.'),
                    Weight::kg($requisition->quantity_kg),
                    ['source_type' => 'requisition', 'source_id' => $requisition->id, 'note' => $requisition->number],
                );
            }

            $requisition->forceFill(['status' => Requisition::ISSUED, 'issued_at' => now()])->save();

            return $requisition;
        });
    }

    private function lock(Requisition $requisition, string ...$allowed): Requisition
    {
        /** @var Requisition $fresh */
        $fresh = Requisition::query()->whereKey($requisition->getKey())->lockForUpdate()->firstOrFail();
        if (! in_array($fresh->status, $allowed, true)) {
            throw new BusinessRuleViolation("Requisition {$fresh->number} is {$fresh->status}.");
        }

        return $fresh;
    }

    /** Maker-checker by person, not by login: one person with two logins is still one person. */
    private function assertNotRequester(Requisition $requisition): void
    {
        $makerPerson = $requisition->created_by
            ? LoginAccount::query()->whereKey($requisition->created_by)->value('person_id')
            : null;
        if ($makerPerson !== null && $makerPerson === $this->actor->personId() && ! SelfApproval::allowed($this->actor->account())) {
            throw new BusinessRuleViolation('The requester cannot approve their own requisition.');
        }
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}
