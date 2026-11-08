<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Alerts\AlertService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Alert;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\LabParameter;
use App\Models\Gate\LabTest;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The lab features a mill may use (the SaaS owner's switches: own tests, quality deductions, time grace, negotiation)
 * and the mill's own rules within them: mandatory tests, approval for a lot in its grace band, tests that may not
 * change the payable weight, and the time the lab has for a lot (with its late rule and expiry alert).
 */
final class LabSetup
{
    public const FEATURES = [
        'custom_tests' => 'Own lab tests (add and change tests and their limits)',
        'deductions' => 'Quality deductions (lab results may change the payable weight)',
        'time_grace' => 'Time grace (time allowed for the lab, late rules, expiry alerts)',
        'negotiation' => 'Negotiation of a lot after its lab test',
    ];

    public const LATE_RULES = ['allow' => 'Allowed, flagged as late', 'approval' => "Allowed only with a supervisor's approval", 'block' => 'Refused'];

    public const STARTS = ['arrived' => 'The vehicle arrives', 'gross_weighed' => 'Its loaded weight is taken'];

    public const ENDS = ['lab_result' => 'The lab result is recorded', 'lab_decision' => 'The lab decides (accept / reject / negotiation)'];

    /** @return array<string, bool> feature => allowed, for a mill (its own setting, else its company's, else all allowed) */
    public static function features(?string $entityId): array
    {
        $row = app(TenantContext::class)->runAsSystem(function () use ($entityId) {
            if ($entityId === null) {
                return null;
            }
            $mill = DB::table('lab_feature_configs')->where('entity_id', $entityId)->first();
            if ($mill !== null) {
                return $mill;
            }
            $customerId = Entity::query()->whereKey($entityId)->value('customer_id');

            return $customerId === null ? null : DB::table('lab_feature_configs')->where('customer_id', $customerId)->first();
        });

        return array_map(fn (string $f) => $row === null ? true : (bool) $row->{$f}, array_combine(array_keys(self::FEATURES), array_keys(self::FEATURES)));
    }

    public static function allows(?string $entityId, string $feature): bool
    {
        return self::features($entityId)[$feature] ?? true;
    }

    /**
     * The SaaS owner's switches for a company (its defaults) or one mill (null: back to its company's).
     *
     * @param  array<string, bool>|null  $features
     */
    public static function configure(?string $customerId, ?string $entityId, ?array $features): void
    {
        $actor = app(ActorContext::class)->account() ?? auth()->user();
        if (! $actor instanceof LoginAccount || ! $actor->isPlatformAdmin()) {
            throw new BusinessRuleViolation('Only the SaaS owner can switch lab features on or off.');
        }
        app(TenantContext::class)->runAsSystem(function () use ($customerId, $entityId, $features, $actor): void {
            $where = $entityId !== null ? ['entity_id' => $entityId] : ['customer_id' => $customerId];
            $before = DB::table('lab_feature_configs')->where($where)->first();
            if ($features === null) {
                DB::table('lab_feature_configs')->where($where)->delete();
            } else {
                $values = array_map(fn ($v) => (bool) $v, array_intersect_key($features, self::FEATURES)) + ['updated_by' => $actor->id, 'updated_at' => now()];
                $before === null
                    ? DB::table('lab_feature_configs')->insert($where + $values + ['id' => (string) Str::uuid(), 'created_at' => now()])
                    : DB::table('lab_feature_configs')->where($where)->update($values);
            }
            app(AuditLogger::class)->record('lab_features.set', null, $before === null ? null : (array) $before, $features, ['customer_id' => $customerId, 'entity_id' => $entityId]);
        });
    }

    /** The mill's time rule, if it has one and the feature is allowed. */
    public static function timeRule(string $entityId): ?object
    {
        if (! self::allows($entityId, 'time_grace')) {
            return null;
        }

        return DB::table('lab_time_rules')->where('entity_id', $entityId)->where('active', true)->first();
    }

    /** When the lab's time for this lot ends (null: no time rule, or the starting step not reached yet). */
    public static function deadline(GateEntry $entry): ?CarbonInterface
    {
        $rule = self::timeRule($entry->entity_id);
        if ($rule === null || ! WeighModes::usesLab(WeighModes::of($entry))) {
            return null;
        }
        $start = $rule->starts_at_event === 'arrived' ? $entry->arrived_at
            : GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', $rule->starts_at_event)->min('occurred_at');

        return $start === null ? null : CarbonImmutable::parse($start)->addMinutes((int) $rule->minutes);
    }

    /**
     * A lab step (a result, or a decision) after the lab's time: allowed and flagged, allowed only for a supervisor
     * (lab.decide plus purchase.approve) with a reason, or refused — by the mill's rule. Returns the flag to keep.
     */
    public static function checkLate(GateEntry $entry, string $step, ?string $reason, ?LoginAccount $by): ?string
    {
        $rule = self::timeRule($entry->entity_id);
        $deadline = self::deadline($entry);
        if ($rule === null || $deadline === null || $rule->ends_at_event !== $step || now()->lte($deadline)) {
            return null;
        }
        $late = 'The lab\'s time for this lot ended at '.$deadline->timezone('Asia/Karachi')->format('d M H:i').'.';

        return match ($rule->late_rule) {
            'block' => throw new BusinessRuleViolation($late.' A late result is not accepted at this mill.'),
            'approval' => ($by?->hasPermission('purchase.approve') ?? false) && mb_strlen(trim((string) $reason)) >= 5
                ? 'lab_late_approved'
                : throw new BusinessRuleViolation($late.' A late result needs a supervisor (with purchase approval) and the reason.'),
            default => 'lab_late',
        };
    }

    /** Mandatory tests with no result in the lot's latest lab test. */
    public static function missingMandatory(GateEntry $entry, LabTest $test): array
    {
        $done = array_keys((array) ($test->results ?? []));

        return app(LabStandards::class)->parametersFor($entry->product_id)
            ->filter(fn (LabParameter $p) => (bool) $p->getAttribute('mandatory') && ! in_array($p->code, $done, true))
            ->map(fn (LabParameter $p) => $p->name_en)->values()->all();
    }

    /** Raises an alert for each lot whose lab time ran out without its result (once per lot). Run every few minutes. */
    public static function alertExpired(): int
    {
        $n = 0;
        $rules = app(TenantContext::class)->runAsSystem(fn () => DB::table('lab_time_rules')->where('active', true)->where('alert_on_expiry', true)->get());
        foreach ($rules as $rule) {
            if (! self::allows($rule->entity_id, 'time_grace')) {
                continue;
            }
            app(TenantContext::class)->runInEntity($rule->entity_id, function () use (&$n): void {
                GateEntry::query()->where('direction', 'inbound')->where('purpose', '!=', 'other')->whereIn('status', ['arrived', 'gross_weighed'])
                    ->where('arrived_at', '>=', now()->subDays(3))->get()
                    ->each(function (GateEntry $e) use (&$n): void {
                        $deadline = self::deadline($e);
                        if ($deadline === null || now()->lte($deadline) || LabTest::query()->where('gate_entry_id', $e->id)->exists()
                            || Alert::query()->where('type', 'lab_time_expired')->where('subject_id', $e->id)->exists()) {
                            return;
                        }
                        app(AlertService::class)->raise('lab_time_expired', 'warning', 'Lab time over: '.$e->vehicleLabel(),
                            'The lab\'s time for '.$e->number.' ended at '.$deadline->timezone('Asia/Karachi')->format('d M H:i').' with no result.',
                            $e, ['deadline' => $deadline->toIso8601String()], 'lab.decide');
                        $n++;
                    });
            });
        }

        return $n;
    }
}
