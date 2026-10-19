<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Domain\Gate\LiveCapture;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;

/** Vehicles inside the mill now, with their stage and the weight they need next. */
final class VehiclesInsideTool extends Tool
{
    public function name(): string
    {
        return 'vehicles_inside';
    }

    public function description(): string
    {
        return 'Vehicles inside the mill now (not exited): number plate, gate entry, party, stage and the weight they need next.';
    }

    public function permission(): string
    {
        return 'gate.entry';
    }

    public function patterns(): array
    {
        return ['/\b(vehicles?|trucks?|gaari|gaadi|inside|at the gate|in the mill)\b/i'];
    }

    public function examples(): array
    {
        return ['vehicles inside'];
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $rows = GateEntry::query()->with('party')->whereNotIn('status', ['exited', 'cancelled'])->orderBy('arrived_at')->limit(40)->get()
            ->map(fn (GateEntry $e) => [$e->registration_no, (string) $e->number, $e->party->name ?? '—', str_replace('_', ' ', $e->status),
                LiveCapture::nextKind($e) ?? '—', $e->arrived_at->timezone('Asia/Karachi')->format('d M H:i')])->all();

        return $rows === [] ? new ToolResult('No vehicle is inside the mill.')
            : new ToolResult(count($rows).' vehicle(s) inside:', [['label' => 'Gate entries', 'url' => GateEntryResource::getUrl()]],
                ['columns' => ['Vehicle', 'Entry', 'Party', 'Stage', 'Needs weight', 'Arrived'], 'rows' => $rows]);
    }
}
