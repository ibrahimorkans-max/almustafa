<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Domain\Gate\GateService;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Masters\PaddyVariety;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Support\Values\PakistanIds;

/**
 * Registers a vehicle arriving at the gate: its number, the party, the type (with or without jack, printed on the
 * token), the item (paddy variety), and optionally the sauda it delivers against and the bags. As on the gate's
 * own form: a party name that is not a registered party is kept as typed (no party record is made).
 */
final class RegisterArrivalTool extends Tool
{
    public function name(): string
    {
        return 'register_vehicle_arrival';
    }

    public function description(): string
    {
        return 'Register a vehicle arriving at the gate: number plate, and optionally the party name, the type (with jack or without jack), '
            .'the item (paddy variety, e.g. "1121" or "Super Basmati"), the purchase sauda number it delivers against and the number of bags.';
    }

    public function properties(): array
    {
        return [
            'registration_no' => ['type' => 'string', 'description' => 'Number plate, e.g. MNA-21-1234.'],
            'party' => ['type' => 'string', 'description' => 'The party\'s name (a registered party is matched; any other name is kept as typed).'],
            'type' => ['type' => 'string', 'enum' => ['with jack', 'without jack'], 'description' => 'Printed on the token.'],
            'item' => ['type' => 'string', 'description' => 'Paddy variety (name or code).'],
            'sauda' => ['type' => 'string', 'description' => 'Sauda number (or its last digits), if it delivers paddy against one.'],
            'bags' => ['type' => 'integer', 'description' => 'Number of bags, if known.'],
        ];
    }

    public function required(): array
    {
        return ['registration_no'];
    }

    public function permission(): string
    {
        return 'gate.entry';
    }

    public function writes(): bool
    {
        return true;
    }

    public function patterns(): array
    {
        return ['/\b(?:arrive|arrived|arrival|register|aayi|aaya)\b\s+(?<registration_no>[A-Za-z]{2,4}[\s\-]?\d{0,2}[\s\-]?\d{1,5})(?:.*\bsauda\s+(?<sauda>[\w\/\-]+))?(?:.*?\b(?<bags>\d{1,5})\s*bags?)?/i'];
    }

    public function examples(): array
    {
        return ['arrived MNA-21-1234 sauda 0001 400 bags'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        $party = filled($input['party'] ?? null) ? self::party((string) $input['party']) : null;
        $variety = filled($input['item'] ?? null) ? self::variety((string) $input['item']) : null;
        $type = self::jack($input['type'] ?? null);

        return 'Register the arrival of '.PakistanIds::normalizeVehicleNumber((string) ($input['registration_no'] ?? ''))
            .(filled($input['party'] ?? null) ? ', party '.($party !== null ? $party->name : $input['party'].' (not a registered party: kept as typed)') : '')
            .($type !== null ? ', '.GateEntry::JACK_TYPES[$type] : '')
            .(filled($input['item'] ?? null) ? ', item '.($variety !== null ? $variety->name : $input['item'].' (no such paddy variety)') : '')
            .(filled($input['sauda'] ?? null) ? ', against sauda '.$input['sauda'] : '')
            .(filled($input['bags'] ?? null) ? ', '.(int) $input['bags'].' bags' : '').'.';
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $data = ['registration_no' => (string) $input['registration_no']];
        if (filled($input['sauda'] ?? null)) {
            $ref = trim((string) $input['sauda']);
            $sauda = Sauda::query()->whereNull('archived_at')->where('direction', 'purchase')->where(fn ($q) => $q->where('number', $ref)->orWhere('number', 'like', '%'.$ref))
                ->orderByDesc('created_at')->first();
            if ($sauda === null) {
                return ToolResult::fail("No purchase sauda {$ref} found.");
            }
            $data['sauda_id'] = $sauda->id;
        }
        if (filled($input['party'] ?? null)) {
            $party = self::party((string) $input['party']);
            if ($party !== null) {
                $data['party_id'] = $party->id;
            } else {
                $data['party_name'] = trim((string) $input['party']);
            }
        }
        if (filled($input['type'] ?? null)) {
            $type = self::jack($input['type']);
            if ($type === null) {
                return ToolResult::fail('The type is "with jack" or "without jack".');
            }
            $data['jack_type'] = $type;
        }
        if (filled($input['item'] ?? null)) {
            $variety = self::variety((string) $input['item']);
            $product = $variety === null ? self::paddy((string) $input['item']) : null;
            if ($variety === null && $product === null) {
                return ToolResult::fail('No paddy variety matches "'.$input['item'].'". Add it under Masters → Paddy varieties.');
            }
            if ($variety !== null) {
                $data['paddy_variety_id'] = $variety->id;
            } else {
                $data['product_id'] = $product?->id;
            }
        }
        if (filled($input['bags'] ?? null)) {
            $data['bag_count'] = (int) $input['bags'];
        }
        $entry = app(GateService::class)->arrive($data);

        return new ToolResult("Arrival registered: {$entry->registration_no}, gate entry {$entry->number}".($entry->flags ? ' (flags: '.implode(', ', $entry->flags).')' : '').'.',
            [['label' => 'Open gate entry', 'url' => GateEntryResource::getUrl('view', ['record' => $entry])]]);
    }

    /** "with", "with jack", "jack" → with_jack; "without", "no jack" → without_jack. */
    public static function jack(mixed $words): ?string
    {
        $w = mb_strtolower(trim((string) $words));

        return match (true) {
            $w === '' => null,
            str_contains($w, 'without') || str_contains($w, 'no jack') || $w === 'without_jack' => 'without_jack',
            str_contains($w, 'with') || $w === 'jack' || $w === 'with_jack' => 'with_jack',
            default => null,
        };
    }

    /** The registered party of that name or code, or the only one whose name contains it. */
    private static function party(string $words): ?Party
    {
        $w = mb_strtolower(trim($words));
        $exact = Party::query()->where('status', 'active')->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [$w])->orWhereRaw('LOWER(code) = ?', [$w]))->first();
        if ($exact !== null || mb_strlen($w) < 3) {
            return $exact;
        }
        $like = Party::query()->where('status', 'active')->whereRaw('LOWER(name) LIKE ?', ['%'.$w.'%'])->limit(2)->get();

        return $like->count() === 1 ? $like->first() : null;
    }

    private static function variety(string $words): ?PaddyVariety
    {
        $w = mb_strtolower(trim($words));

        return PaddyVariety::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [$w])->orWhereRaw('LOWER(code) = ?', [$w])->orWhereRaw('LOWER(name) LIKE ?', ['%'.$w.'%']))
            ->orderByRaw('LOWER(name) = ? DESC', [$w])->orderBy('name')->first();
    }

    private static function paddy(string $words): ?Product
    {
        $w = mb_strtolower(trim($words));

        return Product::query()->where('category', 'paddy')->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.$w.'%'])->orWhereRaw('LOWER(code) = ?', [$w]))->orderBy('name')->first();
    }
}
