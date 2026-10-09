<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Procurement\Quote;
use App\Models\Procurement\QuoteRevision;
use App\Models\Procurement\Sauda;
use Illuminate\Support\Facades\DB;

/**
 * Negotiation: an offer, any number of counter-offers (each kept as a revision), then accept /
 * reject / withdraw. Only the side that did NOT make the latest offer may accept it.
 * Accepting creates the sauda and sends it for approval.
 */
final class QuoteService
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly SaudaService $saudas,
        private readonly ActorContext $actor,
    ) {}

    /** @param  array<string, mixed>  $offer  party_id, product_id, quantity_kg, rate_paise_per_maund, … */
    public function submit(array $offer, string $by, ?string $note = null): Quote
    {
        $this->require('quote.manage');

        return DB::transaction(function () use ($offer, $by, $note): Quote {
            $quote = new Quote($offer + ['last_offer_by' => $by]);
            $quote->number = $this->numbers->next(DocumentNumbers::QUOTE);
            $quote->save();
            $this->revision($quote, 1, $by, $note, $offer['terms'] ?? null);

            return $quote;
        });
    }

    public function counter(Quote $quote, string $quantityKg, int $ratePaisePerMaund, string $by, ?string $note = null): Quote
    {
        $this->require('quote.manage');

        return DB::transaction(function () use ($quote, $quantityKg, $ratePaisePerMaund, $by, $note): Quote {
            $quote = $this->lockOpen($quote);
            if ($quote->last_offer_by === $by) {
                throw new BusinessRuleViolation('Wait for the other side to respond before making another offer.');
            }
            $quote->current_revision++;
            $quote->quantity_kg = $quantityKg;
            $quote->rate_paise_per_maund = $ratePaisePerMaund;
            $quote->last_offer_by = $by;
            $quote->status = Quote::STATUS_COUNTERED;
            $quote->save();
            $this->revision($quote, $quote->current_revision, $by, $note, null);

            return $quote;
        });
    }

    public function accept(Quote $quote, string $by): Sauda
    {
        $this->require('quote.manage');

        return DB::transaction(function () use ($quote, $by): Sauda {
            $quote = $this->lockOpen($quote);
            if ($quote->last_offer_by === $by) {
                throw new BusinessRuleViolation('You cannot accept your own offer.');
            }
            $quote->status = Quote::STATUS_ACCEPTED;
            $quote->save();

            $sauda = $this->saudas->createFromQuote($quote);
            $quote->sauda_id = $sauda->id;
            $quote->save();

            return $sauda;
        });
    }

    public function reject(Quote $quote, string $by, ?string $note = null): Quote
    {
        return $this->close($quote, Quote::STATUS_REJECTED, $by, $note);
    }

    public function withdraw(Quote $quote, string $by, ?string $note = null): Quote
    {
        return $this->close($quote, Quote::STATUS_WITHDRAWN, $by, $note);
    }

    /** Scheduled: open quotes past their validity. */
    public function expireOverdue(): int
    {
        return Quote::query()->whereIn('status', [Quote::STATUS_SUBMITTED, Quote::STATUS_COUNTERED])
            ->where('valid_until', '<', now())->get()
            ->each(function (Quote $q): void {
                $q->status = Quote::STATUS_EXPIRED;
                $q->save();
            })->count();
    }

    private function close(Quote $quote, string $status, string $by, ?string $note): Quote
    {
        $this->require('quote.manage');

        return DB::transaction(function () use ($quote, $status, $by, $note): Quote {
            $quote = $this->lockOpen($quote);
            if ($status === Quote::STATUS_WITHDRAWN && $quote->last_offer_by !== $by) {
                throw new BusinessRuleViolation('Only the side that made the latest offer can withdraw it.');
            }
            $quote->status = $status;
            $quote->save();
            if ($note !== null) {
                $quote->current_revision++;
                $quote->save();
                $this->revision($quote, $quote->current_revision, $by, "[{$status}] {$note}", null);
            }

            return $quote;
        });
    }

    private function lockOpen(Quote $quote): Quote
    {
        /** @var Quote $fresh */
        $fresh = Quote::query()->lockForUpdate()->findOrFail($quote->id);
        if (! $fresh->isOpen()) {
            throw new BusinessRuleViolation("Quote {$fresh->number} is {$fresh->status}.");
        }

        return $fresh;
    }

    /** @param  array<string, mixed>|null  $terms */
    private function revision(Quote $quote, int $revision, string $by, ?string $note, ?array $terms): void
    {
        QuoteRevision::query()->create([
            'entity_id' => $quote->entity_id,
            'quote_id' => $quote->id,
            'revision' => $revision,
            'offered_by' => $by,
            'quantity_kg' => $quote->quantity_kg,
            'rate_paise_per_maund' => $quote->rate_paise_per_maund,
            'terms' => $terms,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}
