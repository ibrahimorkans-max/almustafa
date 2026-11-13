<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Gate\LiveWeight;
use App\Filament\Widgets\LiveWeightBoard;
use App\Models\Gate\Scale;
use Illuminate\Http\JsonResponse;

/**
 * GET /admin/gate/live-weight/{scale}: the weighbridge's current reading, for the dial on the Live weight
 * screen (public/js/live-weight.js asks twice a second). A plain read with no Livewire round trip, so the
 * page and the weighing form next to the dial are never redrawn or reloaded while the weight changes.
 */
final class LiveWeightReadingController
{
    public function __invoke(string $scale, LiveWeight $live): JsonResponse
    {
        abort_unless(LiveWeightBoard::canView(), 403);
        $found = Scale::query()->find($scale) ?? abort(404);
        $r = $live->current($found);

        // With the readings of the last 3 seconds and the server's clock, the dial plays them back at about 10 a second.
        $now = (int) now()->getTimestampMs();

        return response()->json(['kg' => $r['kg'], 'live' => $r['live'], 'stable' => $r['stable'], 'age' => $r['age_seconds'],
            'now' => $now, 'readings' => $r['live'] ? $live->stream($found, $now - 3000) : []])
            ->header('Cache-Control', 'no-store');
    }
}
