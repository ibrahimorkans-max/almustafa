<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Gate\DeductionCalculator;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\DeductionRule;
use App\Models\Gate\GateEntry;
use App\Models\Inventory\PaddyStockEntry;
use App\Models\Inventory\StockEntry;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Paddy stock entries (Stock & production → Paddy stock entry).
 *
 *   net      = gross − tare
 *   deduction = the deduction rules that need no lab reading (fixed weight, % of net, bag weight) for
 *              the product and variety on that date, or a figure typed by someone with a reason
 *   accepted = net − deduction (never below zero: a deduction cannot exceed the net weight)
 *   amount   = accepted (maund) × rate
 *
 * A draft changes nothing. Posting adds the accepted weight to the godown's stock, once (the row is
 * locked and must still be a draft). A gate token can have one stock entry, and only while its slip has
 * not already booked the paddy, so the same lorry is never counted twice; once a stock entry is posted
 * for a token, the gate slip does not book it again (GateService::bookReceipt).
 */
final class PaddyStockEntries
{
    public const SERIES = 'STK';

    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly StockLedger $stock,
        private readonly DeductionCalculator $deductions,
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What a draft would record, without saving: net, deduction (and its lines), accepted, amount.
     *
     * @param  array<string, mixed>  $data
     * @return array{net_kg: string, deduction_kg: string, accepted_kg: string, amount_paise: int|null, lines: list<array<string, mixed>>}
     */
    public function calculate(array $data): array
    {
        $gross = Weight::kg(self::kgString($data['gross_kg'] ?? null, 'Gross weight'));
        $tare = Weight::kg(self::kgString($data['tare_kg'] ?? '0', 'Tare weight'));
        $net = $gross->minus($tare);
        if (bccomp($net->kg, '0', 3) <= 0) {
            throw new BusinessRuleViolation('Gross weight must be more than tare weight.');
        }

        $override = $data['deduction_override_kg'] ?? null;
        if ($override !== null && $override !== '') {
            if (trim((string) ($data['deduction_override_reason'] ?? '')) === '' || mb_strlen(trim((string) $data['deduction_override_reason'])) < 5) {
                throw new BusinessRuleViolation('A deduction typed by hand needs a reason (at least 5 characters).');
            }
            $deduction = Weight::kg(self::kgString($override, 'Deduction'));
            $lines = [['method' => 'override', 'deduction_kg' => $deduction->kg, 'reason' => trim((string) $data['deduction_override_reason'])]];
        } else {
            $rules = filled($data['product_id'] ?? null)
                ? DeductionRule::query()->applicable((string) $data['product_id'], $data['paddy_variety_id'] ?? null,
                    CarbonImmutable::parse((string) ($data['entry_date'] ?? now('Asia/Karachi')->toDateString()), 'Asia/Karachi'))
                    ->whereIn('method', DeductionRule::UNCONDITIONAL)->get()
                : collect();
            $result = $this->deductions->calculate($net->kg, isset($data['bag_count']) && $data['bag_count'] !== '' ? (int) $data['bag_count'] : null, [], $rules);
            $deduction = Weight::kg($result['deduction_kg']);
            $lines = $result['lines'];
        }
        if ($deduction->compare($net) > 0) {
            throw new BusinessRuleViolation('The deduction cannot be more than the net weight ('.$net->kg.' kg).');
        }
        $accepted = $net->minus($deduction);
        $rate = $data['rate_paise_per_maund'] ?? null;
        $amount = $rate === null || $rate === '' ? null
            : (int) bcdiv(bcmul($accepted->in('maund', 6), (string) (int) $rate, 6), '1', 0);

        return ['net_kg' => $net->kg, 'deduction_kg' => $deduction->kg, 'accepted_kg' => $accepted->kg, 'amount_paise' => $amount, 'lines' => $lines];
    }

    /**
     * A draft. With `post` true it is posted at once.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data, bool $post = false): PaddyStockEntry
    {
        $this->require();

        return DB::transaction(function () use ($data, $post): PaddyStockEntry {
            $product = Product::query()->find($data['product_id'] ?? null);
            if ($product === null || $product->category !== 'paddy' || $product->status !== 'active') {
                throw new BusinessRuleViolation('Choose an active paddy product.');
            }
            $godown = Godown::query()->find($data['godown_id'] ?? null);
            if ($godown === null || $godown->status !== 'active') {
                throw new BusinessRuleViolation('Choose an active godown.');
            }
            $gate = null;
            if (filled($data['gate_entry_id'] ?? null)) {
                $gate = GateEntry::query()->lockForUpdate()->find($data['gate_entry_id']) ?? throw new BusinessRuleViolation('Gate token not found.');
                $this->assertTokenFree($gate);
                $data += ['party_id' => $gate->party_id, 'party_name' => $gate->party_name, 'registration_no' => $gate->no_plate ? null : $gate->registration_no,
                    'sauda_id' => $gate->sauda_id, 'paddy_variety_id' => $gate->paddy_variety_id, 'bag_count' => $gate->bag_count];
            }
            if (filled($data['sauda_id'] ?? null)) {
                $sauda = Sauda::query()->find($data['sauda_id']);
                if ($sauda === null || $sauda->direction !== 'purchase') {
                    throw new BusinessRuleViolation('The linked sauda must be a purchase sauda.');
                }
            }
            $figures = $this->calculate($data);

            $entry = new PaddyStockEntry([
                'entry_date' => $data['entry_date'] ?? now('Asia/Karachi')->toDateString(),
                'product_id' => $product->id,
                'paddy_variety_id' => $data['paddy_variety_id'] ?? $product->paddy_variety_id,
                'party_id' => $data['party_id'] ?? null,
                'party_name' => filled($data['party_id'] ?? null) ? null : (filled($data['party_name'] ?? null) ? mb_substr(trim((string) $data['party_name']), 0, 160) : null),
                'godown_id' => $godown->id,
                'registration_no' => filled($data['registration_no'] ?? null) ? mb_substr(strtoupper(trim((string) $data['registration_no'])), 0, 24) : null,
                'gate_entry_id' => $gate?->id,
                'sauda_id' => $data['sauda_id'] ?? null,
                'bag_count' => filled($data['bag_count'] ?? null) ? (int) $data['bag_count'] : null,
                'gross_kg' => Weight::kg(self::kgString($data['gross_kg'], 'Gross weight'))->kg,
                'tare_kg' => Weight::kg(self::kgString($data['tare_kg'] ?? '0', 'Tare weight'))->kg,
                'net_kg' => $figures['net_kg'],
                'deduction_kg' => $figures['deduction_kg'],
                'accepted_kg' => $figures['accepted_kg'],
                'deduction_lines' => $figures['lines'],
                'deduction_override_reason' => filled($data['deduction_override_kg'] ?? null) ? trim((string) $data['deduction_override_reason']) : null,
                'rate_paise_per_maund' => filled($data['rate_paise_per_maund'] ?? null) ? (int) $data['rate_paise_per_maund'] : null,
                'amount_paise' => $figures['amount_paise'],
                'remarks' => $data['remarks'] ?? null,
            ]);
            $entry->number = $this->numbers->next(self::SERIES, CarbonImmutable::parse((string) $entry->entry_date->toDateString(), 'Asia/Karachi'));
            $entry->save();
            if ($entry->deduction_override_reason !== null) {
                $this->audit->record('paddy_stock.deduction_override', $entry, null, ['deduction_kg' => $entry->deduction_kg], ['reason' => $entry->deduction_override_reason]);
            }

            return $post ? $this->post($entry) : $entry;
        });
    }

    /** Adds the accepted weight to the godown's stock, exactly once. */
    public function post(PaddyStockEntry $entry): PaddyStockEntry
    {
        $this->require();

        return DB::transaction(function () use ($entry): PaddyStockEntry {
            $entry = PaddyStockEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->status !== PaddyStockEntry::DRAFT) {
                throw new BusinessRuleViolation('This stock entry is already '.strtolower(PaddyStockEntry::STATUS_LABELS[$entry->status] ?? $entry->status).'.');
            }
            if ($entry->gate_entry_id !== null) {
                $this->assertTokenFree(GateEntry::query()->lockForUpdate()->findOrFail($entry->gate_entry_id), $entry);
            }
            $ledger = $this->stock->post($entry->product, $entry->godown, Weight::kg((string) $entry->accepted_kg), StockEntry::RECEIPT, [
                'source_type' => 'paddy_stock_entry', 'source_id' => $entry->id, 'note' => $entry->number,
            ], CarbonImmutable::parse($entry->entry_date->toDateString(), 'Asia/Karachi')->setTimeFrom(now('Asia/Karachi')));
            $entry->forceFill(['status' => PaddyStockEntry::POSTED, 'stock_entry_id' => $ledger->id, 'posted_at' => now(), 'posted_by' => $this->actor->account()?->id])->save();

            return $entry;
        });
    }

    /** A draft that will not be posted. A posted entry is corrected by a stock count, not cancelled. */
    public function cancel(PaddyStockEntry $entry, string $reason): PaddyStockEntry
    {
        $this->require();
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give a reason (at least 5 characters).');
        }

        return DB::transaction(function () use ($entry, $reason): PaddyStockEntry {
            $entry = PaddyStockEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->status !== PaddyStockEntry::DRAFT) {
                throw new BusinessRuleViolation('Only a draft can be cancelled; correct posted stock with a stock count.');
            }
            // The token is free again for another entry.
            $token = $entry->gateEntry?->tokenLabel();
            $entry->forceFill(['status' => PaddyStockEntry::CANCELLED, 'gate_entry_id' => null,
                'remarks' => trim(($entry->remarks ? $entry->remarks."\n" : '').'Cancelled: '.trim($reason).($token !== null ? ' (was token '.$token.')' : ''))])->save();

            return $entry;
        });
    }

    /** Whether the gate slip of this token already booked its paddy into stock. */
    public static function bookedByGate(GateEntry $gate): bool
    {
        return StockEntry::query()->where('source_type', 'gate_entry')->where('source_id', $gate->id)->where('movement', StockEntry::RECEIPT)->exists();
    }

    private function assertTokenFree(GateEntry $gate, ?PaddyStockEntry $self = null): void
    {
        if ($gate->direction !== 'inbound' || $gate->status === 'cancelled') {
            throw new BusinessRuleViolation('Only an inbound, not cancelled, gate token can be received into stock.');
        }
        if (self::bookedByGate($gate)) {
            throw new BusinessRuleViolation('Token '.$gate->tokenLabel().' is already in stock through its weighbridge slip.');
        }
        $other = PaddyStockEntry::query()->where('gate_entry_id', $gate->id)->where('status', '!=', PaddyStockEntry::CANCELLED)
            ->when($self !== null, fn ($q) => $q->whereKeyNot($self->id))->value('number');
        if ($other !== null) {
            throw new BusinessRuleViolation('Token '.$gate->tokenLabel().' already has stock entry '.$other.'.');
        }
    }

    private static function kgString(mixed $value, string $what): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $value)) {
            throw new BusinessRuleViolation($what.' must be a weight in kg.');
        }

        return $value;
    }

    private function require(): void
    {
        if (! ($this->actor->account()?->hasPermission('stock.adjust') ?? false)) {
            throw new BusinessRuleViolation("You need 'stock.adjust' to book paddy stock entries.");
        }
    }

    /** Used by GateService: a posted stock entry for this token means the slip must not book it again. */
    public static function heldByEntry(GateEntry $gate): bool
    {
        return PaddyStockEntry::query()->where('gate_entry_id', $gate->id)->where('status', PaddyStockEntry::POSTED)->exists();
    }
}
