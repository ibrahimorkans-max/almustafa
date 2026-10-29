<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Gate\LiveWeight;
use App\Filament\Widgets\LiveWeightBoard;
use App\Models\Gate\Scale;
use Illuminate\Http\JsonResponse;

/**
 * GET /admin/gate/live-weight/{scale}: the weighbridge's current reading, for the dial on the Live weight
 * screen (public/js/live-weight.js asks every second). A plain read with no Livewire round trip, so the
 * page and the weighing form next to the dial are never redrawn or reloaded while the weight changes.
 */
final class LiveWeightReadingController
{
    public function __invoke(string $scale, LiveWeight $live): JsonResponse
    {
        abort_unless(LiveWeightBoard::canView(), 403);
        $found = Scale::query()->find($scale) ?? abort(404);
        $r = $live->current($found);

        return response()->json(['kg' => $r['kg'], 'live' => $r['live'], 'stable' => $r['stable'], 'age' => $r['age_seconds']])
            ->header('Cache-Control', 'no-store');
    }
}
