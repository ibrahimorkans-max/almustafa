<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Domain\DailyWages\DailyWages;
use App\Filament\Support\PrintButton;
use App\Models\Accounts\LedgerAccount;
use App\Models\DailyWages\DailyWageCategory;
use App\Models\DailyWages\DailyWageGroup;
use App\Models\DailyWages\DailyWageSheet;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use Illuminate\Support\Facades\DB;

/** Daily wages of a labour group (or one worker) for today: workers, work, quantity; paid now or later. */
final class RecordDailyWagesTool extends Tool
{
    public function name(): string
    {
        return 'record_daily_wages';
    }

    public function description(): string
    {
        return 'Enter today\'s daily wages of casual labour (no registration): labour group (its name or leader) or a single worker\'s name, the work (category name, e.g. "loading"), '
            .'number of workers, quantity (days each, or bags / maund / tons for piece work; default 1), optional rate in rupees, and pay: cash, bank, later or draft (default cash).';
    }

    public function properties(): array
    {
        return [
            'group' => ['type' => 'string', 'description' => 'Labour group name or its leader\'s name.'],
            'worker' => ['type' => 'string', 'description' => 'A single worker\'s name, when there is no group.'],
            'work' => ['type' => 'string', 'description' => 'Work category, e.g. loading, stitching, cleaning.'],
            'workers' => ['type' => 'integer'],
            'quantity' => ['type' => 'number', 'description' => 'Days each (day work) or units of work (bags, maund…). Default 1.'],
            'rate' => ['type' => 'number', 'description' => 'Rupees; default the category rate.'],
            'pay' => ['type' => 'string', 'enum' => ['cash', 'bank', 'later', 'draft']],
        ];
    }

    public function required(): array
    {
        return ['work', 'workers'];
    }

    public function permission(): string
    {
        return 'wages.daily.manage';
    }

    public function writes(): bool
    {
        return true;
    }

    public function patterns(): array
    {
        return ['/^(?:wages|dihari|daily wages)\s+(?<workers>\d{1,3})\s+(?:workers?|labou?r|mazdoor)\s+(?<work>[\w ]{2,30}?)\s+(?:group|under|with)\s+(?<group>[\w .]{2,40}?)(?:\s+(?<quantity>[\d.]+)\s+(?:days?|hours?|bags?|maunds?|tons?|trips?|pieces?))?(?:\s+(?:at|@)\s+(?:rs\.?\s*)?(?<rate>[\d,.]+))?(?:\s+(?:paid|pay)\s+(?<pay>cash|bank|later|draft))?$/i'];
    }

    public function examples(): array
    {
        return ['wages 12 workers loading group Akram', 'wages 4 workers stitching under Rafiq 800 bags pay later'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        $category = $this->category((string) ($input['work'] ?? ''));
        $who = filled($input['group'] ?? null) ? ($this->group((string) $input['group'])?->label() ?? $input['group']) : ($input['worker'] ?? '');
        $rate = filled($input['rate'] ?? null) ? Money::rupees((string) str_replace(',', '', (string) $input['rate']))->paise : ($category === null ? 0 : $category->rate_paise);
        $gross = $category === null ? 0 : DailyWages::gross($category, (int) $input['workers'], (string) ($input['quantity'] ?? '1'), $rate);

        return 'Daily wages today: '.(int) $input['workers'].' worker(s), '.($category === null ? $input['work'] : $category->name).', '.$who
            .' — '.Money::paise($gross)->format().'; '.match ($input['pay'] ?? 'cash') {
                'bank' => 'paid from the bank', 'later' => 'booked, paid later', 'draft' => 'saved as draft', default => 'paid in cash'
            }.'.';
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $category = $this->category((string) $input['work']);
        if ($category === null) {
            return ToolResult::fail('No work category matches "'.$input['work'].'". Add it under Daily wages → Work categories.');
        }
        $group = filled($input['group'] ?? null) ? $this->group((string) $input['group']) : null;
        if (filled($input['group'] ?? null) && $group === null) {
            return ToolResult::fail('No labour group matches "'.$input['group'].'". Add it under Daily wages → Labour groups.');
        }
        $pay = (string) ($input['pay'] ?? 'cash');
        $service = app(DailyWages::class);
        $sheet = DB::transaction(function () use ($service, $input, $category, $group, $pay, $me): DailyWageSheet {
            $sheet = $service->record([
                'work_date' => now('Asia/Karachi')->toDateString(), 'category_id' => $category->id, 'group_id' => $group?->id,
                'worker_name' => $group === null ? (string) ($input['worker'] ?? '') : null, 'workers' => (int) $input['workers'],
                'quantity' => (string) ($input['quantity'] ?? '1'),
                'rate_paise' => filled($input['rate'] ?? null) ? Money::rupees((string) str_replace(',', '', (string) $input['rate']))->paise : null,
            ]);
            if ($pay !== 'draft' && $me->hasPermission('wages.daily.pay')) {
                $from = match ($pay) {
                    'later' => null,
                    'bank' => LedgerAccount::query()->where('kind', 'bank')->where('status', 'active')->orderBy('code')->first(),
                    default => app(ChartOfAccounts::class)->system('cash'),
                };
                $sheet = $service->post($sheet, $from);
            }

            return $sheet;
        });

        return new ToolResult("{$sheet->number}: ".DailyWages::explain($sheet).' — '.(DailyWageSheet::STATUSES[$sheet->status] ?? $sheet->status).'.',
            [['label' => 'Print daily wage slip', 'url' => PrintButton::url('daily_wage', $sheet)]]);
    }

    private function category(string $words): ?DailyWageCategory
    {
        $like = '%'.mb_strtolower(trim($words)).'%';

        return trim($words) === '' ? null : DailyWageCategory::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like]))->orderBy('name')->first();
    }

    private function group(string $words): ?DailyWageGroup
    {
        $like = '%'.mb_strtolower(trim($words)).'%';

        return DailyWageGroup::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(leader_name) LIKE ?', [$like]))->orderBy('name')->first();
    }
}
