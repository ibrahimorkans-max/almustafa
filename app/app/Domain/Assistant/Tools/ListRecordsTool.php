<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Screens;
use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/** Lists or searches the records of any screen: payments, saudas, employees, leave, vouchers… */
final class ListRecordsTool extends Tool
{
    public function name(): string
    {
        return 'list_records';
    }

    public function description(): string
    {
        return 'List or search the records of ANY screen of the ERP (e.g. payments, saudas, purchase bills, sales invoices, vouchers, expenses, employees, '
            .'leave, payroll runs, parties, products, godowns, stock entries, dispatch orders, claims, devices, users). Newest first, with links to open and print each.';
    }

    public function properties(): array
    {
        return [
            'screen' => ['type' => 'string', 'description' => 'What kind of records, e.g. "payments", "leave requests".'],
            'search' => ['type' => 'string', 'description' => 'Optional: number, code, name or words to look for.'],
        ];
    }

    public function required(): array
    {
        return ['screen'];
    }

    public function patterns(): array
    {
        return [
            '/^(?:list|show|search|find)\s+(?<screen>[\w ]{3,40}?)\s+(?:for|with|named|number)\s+(?<search>.{1,60})$/i',
            '/^(?:list|show all|all|latest|recent)\s+(?<screen>[\w ]{3,40})$/i',
        ];
    }

    public function examples(): array
    {
        return ['list payments', 'search saudas for Aslam'];
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $resource = Screens::find((string) $input['screen']);
        if ($resource === null) {
            return ToolResult::fail('I found no list for "'.$input['screen'].'" that you may open.');
        }
        $records = Screens::query($resource, $input['search'] ?? null)->limit(15)->get();
        $label = (string) $resource::getPluralModelLabel();
        if ($records->isEmpty()) {
            return new ToolResult('No '.$label.(filled($input['search'] ?? null) ? ' matching "'.$input['search'].'"' : '').'.', [['label' => ucfirst($label), 'url' => $resource::getUrl()]]);
        }
        $rows = $records->map(function (Model $r): array {
            $details = Screens::details($r);
            $pick = array_values(array_filter($details, fn ($d) => preg_match('/^(Status|Date|Entry date|Payment date|Bill date|Arrived at|Amount|Total|Net|Party|Kind|Direction)/i', $d[0])));

            return [Screens::label($r), implode(' · ', array_map(fn ($d) => $d[0].': '.$d[1], array_slice($pick !== [] ? $pick : array_slice($details, 1), 0, 4)))];
        })->all();
        $links = [['label' => 'All '.$label, 'url' => $resource::getUrl()]];
        foreach ($records->take(5) as $r) {
            $links = [...$links, ...Screens::links($resource, $r)];
        }

        return new ToolResult(ucfirst($label).(filled($input['search'] ?? null) ? ' matching "'.$input['search'].'"' : '').' (newest first):', $links,
            ['columns' => ['Record', 'Details'], 'rows' => $rows]);
    }
}
