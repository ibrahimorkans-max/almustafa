<?php

declare(strict_types=1);

namespace App\Domain\Masters;

use App\Domain\Documents\DocumentNumbers;
use App\Models\Masters\PaddyVariety;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\Vehicle;
use Illuminate\Database\Eloquent\Model;

/**
 * Permanent codes for master records, made when a record is added without one:
 *
 *   Party          PTY-ALI-0001     (first word of the name)
 *   Product        PRD-RICE-0001    (its category)
 *   Paddy variety  PV-1121-0001     (first word of the name)
 *   Vehicle        VEH-000001
 *   Contractor     CTR-ALI-0001     (a party marked labour contractor, beside its PTY code)
 *   Contractor rate CRL-000001      (ContractorRate)
 *
 * Each kind has one never-resetting counter per mill (DocumentNumbers::nextSerial, locked, so two
 * people adding at once never get the same code). The code is kept when the record is renamed. A code
 * typed by hand is kept as it is. Transaction numbers (saudas, gate passes, invoices …) stay with
 * DocumentNumbers; the gate token is a plain number (GateService).
 */
final class MasterCodes
{
    public function __construct(private readonly DocumentNumbers $numbers) {}

    /** Fills `code` on a new record that has none. */
    public function assign(Model $record): void
    {
        if (filled($record->getAttribute('code'))) {
            return;
        }
        $prefix = self::prefix($record);
        if ($prefix === null) {
            return;
        }

        $abbr = self::abbreviation($record);
        do {
            $n = $this->numbers->nextSerial('CODE-'.$prefix, $record->getAttribute('entity_id') ?: null);
            $code = $abbr === null ? sprintf('%s-%06d', $prefix, $n) : sprintf('%s-%s-%04d', $prefix, $abbr, $n);
        } while ($this->taken($record, $code)); // a hand-typed code may already use the number
        $record->setAttribute('code', $code);
    }

    /** A labour contractor's own code, beside its party code: CTR-ALI-0001 (one counter per mill). */
    public function contractorCode(Party $party): string
    {
        $abbr = self::abbreviation($party);
        do {
            $code = sprintf('CTR-%s-%04d', $abbr, $this->numbers->nextSerial('CODE-CTR', $party->entity_id ?: null));
        } while (Party::query()->withoutGlobalScopes()->where('contractor_code', $code)
            ->when(filled($party->entity_id), fn ($q) => $q->where('entity_id', $party->entity_id))->exists());

        return $code;
    }

    public static function prefix(Model $record): ?string
    {
        return match (true) {
            $record instanceof Party => 'PTY',
            $record instanceof Product => 'PRD',
            $record instanceof PaddyVariety => 'PV',
            $record instanceof Vehicle => 'VEH',
            default => null,
        };
    }

    /** The middle part: up to six letters or digits of the first word ("Ali Traders" → ALI); none for vehicles. */
    public static function abbreviation(Model $record): ?string
    {
        if ($record instanceof Vehicle) {
            return null;
        }
        if ($record instanceof Product) {
            return match ((string) $record->getAttribute('category')) {
                'paddy' => 'PADDY', 'rice' => 'RICE', 'by_product' => 'BYP', 'packing' => 'PACK', 'consumable' => 'CONS', default => 'GEN',
            };
        }
        $first = strtok(trim((string) $record->getAttribute('name')), " \t-_/&.,") ?: '';
        $abbr = substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($first)), 0, 6);

        return $abbr === '' ? 'X' : $abbr;
    }

    private function taken(Model $record, string $code): bool
    {
        $query = $record->newQueryWithoutScopes()->where('code', $code);
        if (filled($record->getAttribute('entity_id'))) {
            $query->where('entity_id', $record->getAttribute('entity_id'));
        }

        return $query->exists();
    }
}
