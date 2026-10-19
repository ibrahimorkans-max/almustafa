<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Domain\Gate\LiveWeight;
use App\Filament\Pages\LiveWeightPage;
use App\Models\LoginAccount;

/** The weighbridge reading now. */
final class LiveWeightTool extends Tool
{
    public function name(): string
    {
        return 'live_weight';
    }

    public function description(): string
    {
        return 'The weight on each weighbridge right now, and whether it is stable, moving or has no signal.';
    }

    public function permission(): string
    {
        return 'gate.entry';
    }

    public function patterns(): array
    {
        return ['/\b(live weight|weight now|scale|kanta|weighbridge)\b/i'];
    }

    public function examples(): array
    {
        return ['live weight'];
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $rows = array_map(fn (array $s) => [$s['code'].' · '.$s['name'], $s['kg'] === null ? '—' : number_format($s['kg'], 0).' kg',
            ! $s['live'] ? 'No signal' : ($s['stable'] ? 'Stable' : 'Moving')], app(LiveWeight::class)->all());

        return $rows === [] ? new ToolResult('No weighbridge is set up yet.')
            : new ToolResult('Weighbridge now:', [['label' => 'Live weight screen', 'url' => LiveWeightPage::getUrl()]], ['columns' => ['Weighbridge', 'Weight', 'State'], 'rows' => $rows]);
    }
}
