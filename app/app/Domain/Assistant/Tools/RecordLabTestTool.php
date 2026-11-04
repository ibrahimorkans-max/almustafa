<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Domain\Gate\GateService;
use App\Domain\Gate\LabStandards;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Support\QuickLabTest;
use App\Filament\Support\Units;
use App\Models\Gate\GateEntry;
use App\Models\Gate\LabParameter;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use App\Support\Values\UrduDigits;

/**
 * Records the lab test of a vehicle waiting at the gate: its results (moisture, broken…), judged by the mill's lab
 * rules before Confirm (within standard, within grace, or above grace: rejected automatically). Someone who may
 * decide can accept or reject it in the same step. The rules are GateService::recordLabTest and decideLab, as on
 * the "Lab test" button of the gate screens.
 */
final class RecordLabTestTool extends Tool
{
    public function name(): string
    {
        return 'record_lab_test';
    }

    public function description(): string
    {
        return 'Record the lab test of a vehicle waiting for the lab: the vehicle (number plate or token number), the results in percent by test name '
            .'(e.g. moisture 14, broken 3.5, chalky 2; English or Urdu names), and optionally the decision (accept, reject or later) and remarks. '
            .'The results are judged by the mill\'s lab rules; a result above its grace limit rejects the lot automatically.';
    }

    public function properties(): array
    {
        return [
            'vehicle' => ['type' => 'string', 'description' => 'Number plate (e.g. MNA-21-1234) or token number.'],
            'results' => ['type' => 'object', 'description' => 'Test name or code => percent, e.g. {"moisture": 14, "broken": 3.5}.'],
            'decision' => ['type' => 'string', 'enum' => ['accept', 'reject', 'later'], 'description' => 'Default later (the Lab Supervisor decides).'],
            'remarks' => ['type' => 'string'],
        ];
    }

    public function required(): array
    {
        return ['vehicle', 'results'];
    }

    public function permission(): string
    {
        return 'lab.test';
    }

    public function writes(): bool
    {
        return true;
    }

    public function patterns(): array
    {
        return ['/^lab(?:\s+test)?\s+(?<vehicle>[A-Za-z]{2,4}[\s\-]?\d{0,2}[\s\-]?\d{1,5}|(?:token\s+)?[\d\/\-]{1,12})\s+(?<results>.*\d.*?)(?:\s+(?<decision>accept|reject|later))?$/i'];
    }

    public function examples(): array
    {
        return ['lab MNA-21-1234 moisture 14 broken 3.5', 'lab token 12 moisture 13.5 accept'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        $entry = self::vehicle((string) ($input['vehicle'] ?? ''));
        if ($entry === null) {
            return 'Lab test of '.($input['vehicle'] ?? '?').': no vehicle waiting for the lab matches it.';
        }
        [$results, $unknown] = self::results($input['results'] ?? [], $entry);
        $rows = app(LabStandards::class)->evaluate($results, $entry->product_id);
        $lines = array_map(fn (array $r) => match ($r['verdict']) {
            LabStandards::OVER => '✗ ', LabStandards::GRACE => '⚠ ', default => '✓ ',
        }.LabStandards::describe($r), $rows);
        $over = in_array(LabStandards::OVER, array_column($rows, 'verdict'), true);
        $verdict = match (true) {
            $rows === [] => 'No result given.',
            $over => 'REJECTED automatically: a result is above its grace limit (it can come in only through negotiation).',
            in_array(LabStandards::GRACE, array_column($rows, 'verdict'), true) => 'Within grace: can be accepted, with a warning.',
            default => 'Within standard: can be accepted.',
        };
        $decision = self::decision($input);

        return 'Lab test of '.self::label($entry).":\n".implode("\n", $lines)."\n".$verdict
            .($unknown !== [] ? "\nNot a lab test of this paddy (left out): ".implode(', ', $unknown).'.' : '')
            .(! $over && $decision !== 'later' ? "\nThen: ".($decision === 'accept' ? 'accept the lot (it can be weighed).' : 'reject the lot.') : '')
            .(filled($input['remarks'] ?? null) ? "\nRemarks: ".$input['remarks'] : '');
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $entry = self::vehicle((string) $input['vehicle']);
        if ($entry === null) {
            return ToolResult::fail('No vehicle waiting for the lab matches "'.$input['vehicle'].'". Say "vehicles inside" to see them.');
        }
        [$results] = self::results($input['results'], $entry);
        if ($results === []) {
            return ToolResult::fail('Give at least one result, e.g. moisture 14.');
        }
        $decision = self::decision($input);
        if ($decision === 'reject' && blank($input['remarks'] ?? null)) {
            return ToolResult::fail('Say why it is rejected (remarks).');
        }
        $gate = app(GateService::class);
        $gate->recordLabTest($entry, ['results' => $results, 'remarks' => filled($input['remarks'] ?? null) ? (string) $input['remarks'] : null]);
        $entry = $entry->fresh() ?? $entry;
        $over = app(LabStandards::class)->overGrace($entry);
        if ($over === [] && $decision !== 'later' && $me->hasPermission('lab.decide')) {
            $gate->decideLab($entry, $decision === 'accept', filled($input['remarks'] ?? null) ? (string) $input['remarks'] : null);
            $entry = $entry->fresh() ?? $entry;
        }
        $who = self::label($entry);
        $text = match (true) {
            $over !== [] => "{$who}: rejected automatically. It can come in only through negotiation (Gate → Negotiation).",
            $entry->status === 'lab_accepted' => "{$who}: lab result saved and accepted. It can go on the weighbridge now.",
            $entry->status === 'lab_rejected' => "{$who}: lab result saved and rejected. It can come in only through negotiation.",
            default => "{$who}: lab result saved. Waiting for the Lab Supervisor to accept or reject it.",
        };

        return new ToolResult($text, [['label' => 'Open the vehicle', 'url' => GateEntryResource::getUrl('view', ['record' => $entry])]]);
    }

    /** A vehicle waiting for the lab, by its number plate or token. */
    public static function vehicle(string $words): ?GateEntry
    {
        $words = trim(UrduDigits::toLatin($words));
        if ($words === '') {
            return null;
        }
        $ids = array_keys(QuickLabTest::waiting());
        $entries = GateEntry::query()->with(['party', 'product'])->whereIn('id', $ids)->get();
        $plate = PakistanIds::normalizeVehicleNumber($words);
        $token = (string) preg_replace('/^token\s*/i', '', $words);

        return $entries->first(fn (GateEntry $e) => $e->registration_no === $plate)
            ?? $entries->first(fn (GateEntry $e) => $token !== '' && ($e->tokenLabel() === $token || (ctype_digit($token) && (int) $e->token_serial === (int) $token)));
    }

    /**
     * The results as basis points by test code, from {"moisture": 14} or "moisture 14, broken 3.5" (names in English
     * or Urdu, or codes); and the names that are not lab tests of this vehicle's paddy.
     *
     * @return array{0: array<string, int>, 1: list<string>}
     */
    public static function results(mixed $given, GateEntry $entry): array
    {
        $pairs = [];
        if (is_array($given)) {
            $pairs = $given;
        } else {
            preg_match_all('/([\p{L}][\p{L}\p{M} _\-]*?)\s*[:=]?\s*(\d+(?:\.\d+)?)\s*%?/u', UrduDigits::toLatin((string) $given), $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $pairs[trim($hit[1])] = $hit[2];
            }
        }
        $params = app(LabStandards::class)->parametersFor($entry->product_id);
        $out = [];
        $unknown = [];
        foreach ($pairs as $name => $value) {
            $value = UrduDigits::amount((string) $value);
            if ($value === '' || preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) !== 1) {
                continue;
            }
            $key = mb_strtolower(trim((string) $name));
            $param = $params->first(fn (LabParameter $p) => in_array($key, [mb_strtolower($p->code), mb_strtolower($p->name_en), mb_strtolower($p->name_ur)], true))
                ?? $params->first(fn (LabParameter $p) => mb_strlen($key) >= 3 && (str_starts_with(mb_strtolower($p->name_en), $key) || str_contains(mb_strtolower($p->name_en), $key)));
            if ($param === null) {
                $unknown[] = (string) $name;

                continue;
            }
            $out[$param->code] = (int) Units::percentToBp($value);
        }

        return [$out, $unknown];
    }

    /** @param  array<string, mixed>  $input */
    private static function decision(array $input): string
    {
        $d = mb_strtolower(trim((string) ($input['decision'] ?? '')));

        return in_array($d, ['accept', 'reject'], true) ? $d : 'later';
    }

    private static function label(GateEntry $e): string
    {
        return ($e->token_serial !== null || $e->token_no !== null ? 'Token '.$e->tokenLabel().' · ' : '').$e->vehicleLabel()
            .($e->partyLabel() !== null ? ' · '.$e->partyLabel() : '').' · '.$e->itemLabel();
    }
}
