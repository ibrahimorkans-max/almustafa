<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Gate\GateService;
use App\Domain\Gate\LabStandards;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\Gate\LabParameter;
use App\Models\Gate\LabTest;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * "Lab test" on the gate screens: choose a vehicle waiting for the lab, type its results, and see at once what
 * the rules make of them (within standard, within grace, or above grace: rejected automatically). Someone who
 * may decide can accept or reject in the same step; otherwise it waits for the Lab Supervisor. The rules are
 * GateService::recordLabTest and decideLab, the same as on the vehicle page.
 */
final class QuickLabTest
{
    private const PERCENT = '/^\d{1,3}(\.\d{1,2})?$/';

    public static function make(): Action
    {
        return Action::make('quickLabTest')->label('Lab test')->icon('heroicon-o-beaker')->color('info')
            ->visible(fn () => self::can('lab.test'))
            ->modalHeading('Lab test')
            ->modalDescription('Choose the vehicle, type the results, and check the result below before saving.')
            ->modalSubmitActionLabel('Save lab result')
            ->form([
                Select::make('entry')->label('Vehicle (token)')->required()->searchable()->live()
                    ->options(fn () => self::waiting())
                    ->noSearchResultsMessage('No vehicle is waiting for the lab.')
                    ->afterStateUpdated(fn (callable $set) => $set('decision', 'later')),
                Grid::make(2)->visible(fn (Get $get) => self::entry($get('entry')) !== null)
                    ->schema(fn (Get $get): array => self::resultFields(self::entry($get('entry')))),
                Placeholder::make('outcome')->label('Result by the rules')
                    ->visible(fn (Get $get) => self::entry($get('entry')) !== null)
                    ->content(fn (Get $get) => self::outcome($get)),
                Radio::make('decision')->label('Decision')->default('later')->live()
                    ->visible(fn (Get $get) => self::can('lab.decide') && self::entry($get('entry')) !== null)
                    ->options(fn (Get $get) => self::over($get) !== []
                        ? ['later' => 'Rejected automatically (a result is above its grace limit)']
                        : ['accept' => 'Accept the lot: it can be weighed', 'reject' => 'Reject the lot', 'later' => 'Leave the decision to the Lab Supervisor']),
                Textarea::make('note')->label(fn (Get $get) => $get('decision') === 'reject' ? 'Why is it rejected?' : 'Remarks (optional)')
                    ->visible(fn (Get $get) => self::entry($get('entry')) !== null)
                    ->required(fn (Get $get) => $get('decision') === 'reject')->rows(2)->maxLength(1000),
            ])
            ->action(function (array $data, Action $action): void {
                $entry = self::entry($data['entry'] ?? null) ?? throw new BusinessRuleViolation('Choose the vehicle.');
                $results = [];
                foreach ($data as $key => $value) {
                    if (str_starts_with((string) $key, 'r_') && filled($value)) {
                        $results[substr((string) $key, 2)] = Units::percentToBp((string) $value);
                    }
                }
                $gate = app(GateService::class);
                try {
                    $gate->recordLabTest($entry, ['results' => $results, 'remarks' => $data['note'] ?? null]);
                    $decision = $data['decision'] ?? 'later';
                    if ($decision !== 'later' && self::can('lab.decide') && app(LabStandards::class)->overGrace($entry->fresh()) === []) {
                        $gate->decideLab($entry->fresh(), $decision === 'accept', $data['note'] ?? null);
                    }
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }
                self::saved($entry->fresh());
                $action->getLivewire()->dispatch('weigh-queue-refresh');
            });
    }

    /** @return array<string, string> inbound lots waiting for the lab (no decision yet), in token order */
    public static function waiting(): array
    {
        return GateEntry::query()->with(['party', 'product'])
            ->where('direction', 'inbound')->where('purpose', '!=', 'other')->where('status', 'arrived')
            ->orderByDesc('arrived_at')->limit(100)->get()
            ->sortBy([fn (GateEntry $a, GateEntry $b) => ($a->token_serial === null) <=> ($b->token_serial === null),
                fn (GateEntry $a, GateEntry $b) => (int) $a->token_serial <=> (int) $b->token_serial,
                fn (GateEntry $a, GateEntry $b) => (string) $a->arrived_at?->format('Y-m-d H:i:s') <=> (string) $b->arrived_at?->format('Y-m-d H:i:s')])
            ->mapWithKeys(fn (GateEntry $e) => [$e->id => trim(($e->token_serial !== null || $e->token_no !== null ? 'Token '.$e->tokenLabel().' · ' : '')
                .$e->vehicleLabel().($e->partyLabel() !== null ? ' · '.$e->partyLabel() : '').' · '.$e->itemLabel()
                .(LabTest::query()->where('gate_entry_id', $e->id)->exists() ? ' · result recorded, waiting for decision' : ''))])
            ->all();
    }

    /** @return list<TextInput> one box per lab test of the vehicle's product, with its limits */
    private static function resultFields(?GateEntry $entry): array
    {
        if ($entry === null) {
            return [];
        }
        $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');

        return app(LabStandards::class)->parametersFor($entry->product_id)->map(fn (LabParameter $p) => TextInput::make('r_'.$p->code)
            ->label("{$p->name_en} ({$p->name_ur})")->suffix($p->unit)->inputMode('decimal')->placeholder('e.g. '.$pct($p->standard_bp))
            ->helperText('OK up to '.$pct($p->standard_bp).' · grace up to '.$pct($p->grace_bp))
            ->regex(self::PERCENT)->validationMessages(['regex' => 'Type a number such as 14 or 14.5'])
            ->live(onBlur: true))->values()->all();
    }

    /** @return list<array<string, mixed>> the typed results, judged by the rules */
    private static function evaluated(Get $get): array
    {
        $entry = self::entry($get('entry'));
        if ($entry === null) {
            return [];
        }
        $results = [];
        foreach (app(LabStandards::class)->parametersFor($entry->product_id) as $code => $p) {
            $value = (string) $get('r_'.$code);
            if ($value !== '' && preg_match(self::PERCENT, $value) === 1) {
                $results[$code] = (int) Units::percentToBp($value);
            }
        }

        return app(LabStandards::class)->evaluate($results, $entry->product_id);
    }

    /** @return list<array<string, mixed>> */
    private static function over(Get $get): array
    {
        return array_values(array_filter(self::evaluated($get), fn (array $r) => $r['verdict'] === LabStandards::OVER));
    }

    private static function outcome(Get $get): HtmlString
    {
        $rows = self::evaluated($get);
        if ($rows === []) {
            return new HtmlString('<span class="text-gray-500">Type the results above; the verdict shows here.</span>');
        }
        $lines = array_map(fn (array $r) => '<li style="color:'.match ($r['verdict']) {
            LabStandards::OVER => '#dc2626">✗ ',
            LabStandards::GRACE => '#d97706">⚠ ',
            default => '#16a34a">✓ ',
        }.e(LabStandards::describe($r)).'</li>', $rows);
        $verdict = match (true) {
            self::over($get) !== [] => '<strong style="color:#dc2626">REJECTED automatically: a result is above its grace limit. It can come in only through negotiation.</strong>',
            in_array(LabStandards::GRACE, array_column($rows, 'verdict'), true) => '<strong style="color:#d97706">Within grace: can be accepted, with a warning.</strong>',
            default => '<strong style="color:#16a34a">Within standard: can be accepted.</strong>',
        };

        return new HtmlString('<ul style="margin:0 0 .5rem 0">'.implode('', $lines).'</ul>'.$verdict);
    }

    /** What happened, in words: accepted and ready to weigh, rejected (and why), or waiting for the decision. */
    private static function saved(GateEntry $entry): void
    {
        $who = ($entry->token_serial !== null || $entry->token_no !== null ? 'Token '.$entry->tokenLabel().' · ' : '').$entry->vehicleLabel();
        $over = app(LabStandards::class)->overGrace($entry);
        $note = match (true) {
            $over !== [] => Notification::make()->danger()->title($who.': rejected automatically')
                ->body(implode("\n", array_map(fn (array $r) => LabStandards::describe($r), $over))."\nIt can come in only through negotiation (Gate → Negotiation)."),
            $entry->status === 'lab_accepted' => Notification::make()->success()->title($who.': accepted')->body('It can go on the weighbridge now.'),
            $entry->status === 'lab_rejected' => Notification::make()->danger()->title($who.': rejected')->body('It can come in only through negotiation (Gate → Negotiation).'),
            default => Notification::make()->info()->title($who.': lab result saved')->body('Waiting for the Lab Supervisor to accept or reject it.'),
        };
        $note->duration(10000)->send();
    }

    private static function entry(mixed $id): ?GateEntry
    {
        return is_string($id) && $id !== '' ? GateEntry::query()->with(['party', 'product'])->find($id) : null;
    }

    private static function can(string $permission): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission($permission);
    }
}
