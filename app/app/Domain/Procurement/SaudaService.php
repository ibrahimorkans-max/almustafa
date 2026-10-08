<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Procurement\Quote;
use App\Models\Procurement\Sauda;
use App\Models\Procurement\SaudaEvent;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sauda lifecycle:  draft → pending_approval → confirmed → in_delivery → completed
 *                                         ↘ (rejected → draft)      ↘ cancelled / expired
 * Every transition is an append-only sauda event with actor and time. Approval is
 * maker-checker: the approver must be a different PERSON holding `deal.approve`.
 */
final class SaudaService
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly ActorContext $actor,
    ) {}

    /** @param  array<string, mixed>  $terms */
    public function create(array $terms): Sauda
    {
        $this->require(($terms['direction'] ?? 'purchase') === 'sale' ? 'sales.order' : 'deal.create');

        return DB::transaction(function () use ($terms): Sauda {
            $sauda = new Sauda($terms);
            $sauda->number = $this->numbers->next(($terms['direction'] ?? 'purchase') === 'sale' ? DocumentNumbers::SAUDA_SALE : DocumentNumbers::SAUDA_PURCHASE);
            $sauda->save();
            $this->event($sauda, 'created', ['terms' => $sauda->only(Sauda::FROZEN_TERMS)]);

            return $sauda;
        });
    }

    /** Accepting a quote creates the sauda straight into approval. */
    public function createFromQuote(Quote $quote): Sauda
    {
        $sauda = $this->create([
            'direction' => 'purchase',
            'party_id' => $quote->party_id,
            'broker_party_id' => $quote->broker_party_id,
            'product_id' => $quote->product_id,
            'quantity_kg' => $quote->quantity_kg,
            'rate_paise_per_quintal' => $quote->rate_paise_per_quintal,
            'quality_terms' => $quote->requirement?->quality_spec,
            'delivery_from' => $quote->requirement?->delivery_from,
            'delivery_to' => $quote->requirement?->delivery_to,
            'quote_id' => $quote->id,
            'requirement_id' => $quote->requirement_id,
        ]);

        return $this->submit($sauda);
    }

    public function submit(Sauda $sauda): Sauda
    {
        $this->require($sauda->direction === 'sale' ? 'sales.order' : 'deal.create');

        return $this->transition($sauda, [Sauda::STATUS_DRAFT], Sauda::STATUS_PENDING, 'submitted');
    }

    public function approve(Sauda $sauda, ?string $note = null): Sauda
    {
        $approver = $this->require('deal.approve');
        $this->assertDifferentPerson($sauda, $approver);

        return $this->transition($sauda, [Sauda::STATUS_PENDING], Sauda::STATUS_CONFIRMED, 'approved', ['note' => $note],
            function (Sauda $s) use ($approver): void {
                $s->approved_by = $approver->id;
                $s->approved_at = now();
            });
    }

    public function reject(Sauda $sauda, string $reason): Sauda
    {
        $approver = $this->require('deal.approve');
        $this->assertDifferentPerson($sauda, $approver);

        return $this->transition($sauda, [Sauda::STATUS_PENDING], Sauda::STATUS_DRAFT, 'rejected', ['reason' => $reason]);
    }

    public function cancel(Sauda $sauda, string $reason): Sauda
    {
        $this->require('deal.approve');

        return $this->transition($sauda, [Sauda::STATUS_DRAFT, Sauda::STATUS_PENDING, Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY],
            Sauda::STATUS_CANCELLED, 'cancelled', ['reason' => $reason, 'delivered_kg' => $sauda->delivered_kg],
            function (Sauda $s) use ($reason): void {
                $s->closed_at = now();
                $s->close_reason = $reason;
            });
    }

    /**
     * Adds a completed vehicle's payable weight to the deal. Over-delivery beyond the tolerance
     * is recorded (true record) and flagged, never silently capped.
     */
    public function recordDelivery(Sauda $sauda, GateEntry $entry, Weight $payable): Sauda
    {
        return DB::transaction(function () use ($sauda, $entry, $payable): Sauda {
            /** @var Sauda $sauda */
            $sauda = Sauda::query()->lockForUpdate()->findOrFail($sauda->id);
            if (! $sauda->isOpenForDelivery()) {
                throw new BusinessRuleViolation("Sauda {$sauda->number} is {$sauda->status}; it cannot take deliveries.");
            }

            $delivered = Weight::kg($sauda->delivered_kg)->plus($payable);
            $over = $delivered->compare($sauda->maximumQuantity()) > 0;
            $sauda->delivered_kg = $delivered->kg;
            $sauda->status = Sauda::STATUS_IN_DELIVERY;
            $sauda->save();
            $this->event($sauda, 'delivery', [
                'gate_entry_id' => $entry->id, 'gate_entry_number' => $entry->number, 'payable_kg' => $payable->kg,
                'delivered_kg' => $delivered->kg, 'over_tolerance' => $over,
            ]);

            if ($delivered->compare($sauda->completionThreshold()) >= 0) {
                $sauda->status = Sauda::STATUS_COMPLETED;
                $sauda->completed_at = now();
                $sauda->save();
                $this->event($sauda, 'completed', ['delivered_kg' => $delivered->kg]);
            }

            return $sauda;
        });
    }

    /** Scheduled: deals whose delivery period ended are closed as expired, remaining quantity recorded. */
    public function expireOverdue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now('Asia/Kolkata')->startOfDay();
        $overdue = Sauda::query()
            ->whereIn('status', [Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY])
            ->whereDate('delivery_to', '<', $today->toDateString())
            ->get();

        foreach ($overdue as $sauda) {
            $this->transition($sauda, [Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY], Sauda::STATUS_EXPIRED, 'expired',
                ['remaining_kg' => $sauda->remaining()->kg],
                function (Sauda $s): void {
                    $s->closed_at = now();
                    $s->close_reason = 'Delivery period ended';
                });
        }

        return $overdue->count();
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $data
     * @param  (callable(Sauda): void)|null  $mutate
     */
    private function transition(Sauda $sauda, array $from, string $to, string $event, array $data = [], ?callable $mutate = null): Sauda
    {
        return DB::transaction(function () use ($sauda, $from, $to, $event, $data, $mutate): Sauda {
            /** @var Sauda $fresh */
            $fresh = Sauda::query()->lockForUpdate()->findOrFail($sauda->id);
            if (! in_array($fresh->status, $from, true)) {
                throw new BusinessRuleViolation("Sauda {$fresh->number} is {$fresh->status}; cannot be {$event}.");
            }
            $fresh->status = $to;
            if ($mutate !== null) {
                $mutate($fresh);
            }
            $fresh->save();
            $this->event($fresh, $event, $data);

            return $fresh;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function event(Sauda $sauda, string $event, array $data): void
    {
        SaudaEvent::query()->create([
            'entity_id' => $sauda->entity_id,
            'sauda_id' => $sauda->id,
            'event' => $event,
            'data' => $data,
            'actor_login_account_id' => $this->actor->accountId(),
            'occurred_at' => now(),
        ]);
    }

    private function require(string $permission): LoginAccount
    {
        $account = $this->actor->account();
        if ($account === null || ! $account->hasPermission($permission)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }

        return $account;
    }

    private function assertDifferentPerson(Sauda $sauda, LoginAccount $approver): void
    {
        $makerPerson = $sauda->created_by
            ? LoginAccount::query()->withoutGlobalScopes()->whereKey($sauda->created_by)->value('person_id')
            : null;
        if ($makerPerson !== null && $makerPerson === $approver->person_id) {
            throw new BusinessRuleViolation('The person who created a sauda cannot approve or reject it (maker-checker).');
        }
    }
}
