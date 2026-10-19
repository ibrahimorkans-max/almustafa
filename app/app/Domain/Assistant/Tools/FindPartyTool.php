<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Filament\Resources\Masters\PartyResource;
use App\Models\LoginAccount;
use App\Models\Masters\Party;

/** Look up growers, arhtis, customers, brokers and transporters. */
final class FindPartyTool extends Tool
{
    public function name(): string
    {
        return 'find_party';
    }

    public function description(): string
    {
        return 'Find a party (grower, arhti, supplier, customer, broker, transporter) by part of its name, code, city or phone.';
    }

    public function properties(): array
    {
        return ['query' => ['type' => 'string', 'description' => 'Part of the name, code, city or phone.']];
    }

    public function required(): array
    {
        return ['query'];
    }

    public function permission(): string
    {
        return 'master.view';
    }

    public function patterns(): array
    {
        return ['/\b(?:find|search|who is|party|customer|supplier|grower)\s+(?<query>[\w\-\. &]{2,60})$/i'];
    }

    public function examples(): array
    {
        return ['find Aslam'];
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $q = trim((string) ($input['query'] ?? ''));
        if (mb_strlen($q) < 2) {
            return ToolResult::fail('Type at least two letters of the name.');
        }
        $like = '%'.mb_strtolower($q).'%';
        $rows = Party::query()->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like])
            ->orWhereRaw('LOWER(city) LIKE ?', [$like])->orWhere('phone', 'like', "%{$q}%"))->orderBy('name')->limit(15)->get()
            ->map(fn (Party $p) => [$p->code, $p->name, implode(', ', array_map(fn ($r) => str_replace('is_', '', $r), array_filter(Party::ROLES, fn ($r) => (bool) $p->{$r}))),
                $p->city ?? '—', $p->phone ?? '—'])->all();

        return $rows === [] ? new ToolResult("No party matches \"{$q}\".")
            : new ToolResult('Parties found:', [['label' => 'Parties', 'url' => PartyResource::getUrl()]], ['columns' => ['Code', 'Name', 'Kind', 'City', 'Phone'], 'rows' => $rows]);
    }
}
