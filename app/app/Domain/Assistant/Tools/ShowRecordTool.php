<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Screens;
use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Models\LoginAccount;

/** Shows one record of any screen, with links to open and print it. */
final class ShowRecordTool extends Tool
{
    public function name(): string
    {
        return 'show_record';
    }

    public function description(): string
    {
        return 'Show one record of ANY screen in full (found by its number, code, plate or name), with links to open it and to PRINT its slip, bill, voucher or record sheet.';
    }

    public function properties(): array
    {
        return [
            'screen' => ['type' => 'string', 'description' => 'What kind of record, e.g. "payment", "gate entry", "employee".'],
            'ref' => ['type' => 'string', 'description' => 'Its number, code, plate or name.'],
        ];
    }

    public function required(): array
    {
        return ['screen', 'ref'];
    }

    public function patterns(): array
    {
        return ['/^(?:show|open|print|details? of)\s+(?<screen>[\w ]{3,30}?)\s+(?<ref>[\w\/\-\.]{2,40})$/i'];
    }

    public function examples(): array
    {
        return ['show sauda 0001', 'print payment AMR/PAY/2026-27/0003'];
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $resource = Screens::find((string) $input['screen']);
        if ($resource === null) {
            return ToolResult::fail('I found no screen for "'.$input['screen'].'" that you may open.');
        }
        $matches = Screens::query($resource, (string) $input['ref'])->limit(6)->get();
        if ($matches->isEmpty()) {
            return ToolResult::fail('No '.$resource::getModelLabel().' matches "'.$input['ref'].'".');
        }
        if ($matches->count() > 1) {
            $exact = $matches->first(fn ($r) => mb_strtolower(Screens::label($r)) === mb_strtolower(trim((string) $input['ref'])) || str_ends_with(Screens::label($r), (string) $input['ref']));
            if ($exact === null) {
                return new ToolResult('Several match; which one?', array_merge(...$matches->map(fn ($r) => Screens::links($resource, $r))->all()),
                    ['columns' => ['Record'], 'rows' => $matches->map(fn ($r) => [Screens::label($r)])->all()]);
            }
            $matches = collect([$exact]);
        }
        $record = $matches->first();

        return new ToolResult(ucfirst((string) $resource::getModelLabel()).' '.Screens::label($record).':', Screens::links($resource, $record),
            ['columns' => ['Field', 'Value'], 'rows' => Screens::details($record)]);
    }
}
