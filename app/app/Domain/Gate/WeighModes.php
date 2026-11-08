<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\WeighmentModeChange;
use App\Models\WeighmentModeConfig;
use Illuminate\Support\Facades\DB;

/**
 * The four ways a lot of paddy may be weighed and tested. Which of them a mill may use is the SaaS owner's decision
 * alone (System → Weighment modes): a company's defaults, and a mill's own setting where the SaaS owner gives it one.
 * The mill sees them and picks which enabled mode new vehicles start in; it cannot switch any on or off.
 *
 *  1 lab_before_slip        weighed first, lab after; the final slip waits for the lab (and its approval)
 *  2 no_lab                 no lab: loaded weight, empty weight, slip with the weights only
 *  3 weigh_first_lab_later  weighed first with a weight-only slip at once; the lab later; then the final slip (both kept)
 *  4 lab_first              the lab first: accepted, rejected or negotiated; only then weighed
 *
 * A vehicle keeps the mode it was registered in (gate_entries.weigh_mode, with the setting's version): switching a
 * mode off later never changes a vehicle already in the mill or one already weighed.
 */
final class WeighModes
{
    public const LAB_BEFORE_SLIP = 'lab_before_slip';

    public const NO_LAB = 'no_lab';

    public const WEIGH_FIRST_LAB_LATER = 'weigh_first_lab_later';

    public const LAB_FIRST = 'lab_first';

    /** In their numbers' order. */
    public const ALL = [self::LAB_BEFORE_SLIP, self::NO_LAB, self::WEIGH_FIRST_LAB_LATER, self::LAB_FIRST];

    public const LABELS = [
        self::LAB_BEFORE_SLIP => 'Mode 1 — Lab test before the final slip',
        self::NO_LAB => 'Mode 2 — Weight without a lab test',
        self::WEIGH_FIRST_LAB_LATER => 'Mode 3 — Weight first, lab test later',
        self::LAB_FIRST => 'Mode 4 — Lab test first (accepted / rejected / negotiation), then weight',
    ];

    public const SHORT = [
        self::LAB_BEFORE_SLIP => 'Lab before final slip',
        self::NO_LAB => 'Without lab',
        self::WEIGH_FIRST_LAB_LATER => 'Weight first, lab later',
        self::LAB_FIRST => 'Lab first',
    ];

    public const HELP = [
        self::LAB_BEFORE_SLIP => 'Entry → loaded weight → lab test → approval → final slip. The final slip waits until the lab is done.',
        self::NO_LAB => 'Entry → loaded weight → empty weight → slip. No lab test; the slip shows only the vehicle and weights.',
        self::WEIGH_FIRST_LAB_LATER => 'Entry → loaded weight → weight-only slip at once → lab test → final slip. Both slips are kept.',
        self::LAB_FIRST => 'Entry → sample → lab test → accepted / rejected / negotiation → weighbridge → final slip.',
    ];

    /** The mill's choice of mode new vehicles start in (among the enabled ones). */
    public const DEFAULT_SETTING = 'gate.default_mode';

    /** @var array<string, array{modes: list<string>, version: string, level: string}> */
    private array $cache = [];

    /**
     * The modes a mill may use now, the setting they come from and its version ("mill:3", "company:2", or
     * "before-modes" for a mill the SaaS owner has not set yet, which keeps its earlier weigh-before-lab setting).
     *
     * @return array{modes: list<string>, version: string, level: string}
     */
    public static function effective(?string $entityId): array
    {
        $self = app(self::class);

        return $self->cache[$entityId ?? ''] ??= app(TenantContext::class)->runAsSystem(function () use ($entityId): array {
            if ($entityId !== null) {
                $mill = WeighmentModeConfig::query()->where('entity_id', $entityId)->first();
                if ($mill !== null) {
                    return ['modes' => $mill->enabled(), 'version' => 'mill:'.$mill->version, 'level' => 'mill'];
                }
                $customerId = Entity::query()->whereKey($entityId)->value('customer_id');
                $company = $customerId === null ? null : WeighmentModeConfig::query()->where('customer_id', $customerId)->first();
                if ($company !== null) {
                    return ['modes' => $company->enabled(), 'version' => 'company:'.$company->version, 'level' => 'company'];
                }
            }

            return ['modes' => [self::legacy($entityId)], 'version' => 'before-modes', 'level' => 'default'];
        });
    }

    /** @return list<string> */
    public static function enabled(?string $entityId): array
    {
        return self::effective($entityId)['modes'];
    }

    /** @return array<string, string> the enabled modes with their short names, for a choice */
    public static function options(?string $entityId): array
    {
        return array_intersect_key(self::SHORT, array_flip(self::enabled($entityId)));
    }

    public static function isEnabled(?string $entityId, string $mode): bool
    {
        return in_array($mode, self::enabled($entityId), true);
    }

    /** The mode a new paddy vehicle starts in: the mill's choice if still enabled, else the first enabled one. */
    public static function defaultMode(?string $entityId): ?string
    {
        $enabled = self::enabled($entityId);
        $chosen = (string) app(SettingsService::class)->get(self::DEFAULT_SETTING, $entityId);

        return in_array($chosen, $enabled, true) ? $chosen : ($enabled[0] ?? null);
    }

    /**
     * The mode for a new paddy vehicle: the one asked for (it must be enabled for the mill), else the mill's default.
     *
     * @return array{0: string, 1: string} [mode, config version]
     */
    public static function forNewVehicle(?string $entityId, ?string $asked): array
    {
        $asked = $asked === '' ? null : $asked;
        if ($asked !== null && ! in_array($asked, self::ALL, true)) {
            throw new BusinessRuleViolation('Unknown weighing mode.');
        }
        if ($asked !== null && ! self::isEnabled($entityId, $asked)) {
            throw new BusinessRuleViolation(self::SHORT[$asked].' is not switched on for this mill. Choose one of: '
                .implode(', ', array_map(fn (string $m) => self::SHORT[$m], self::enabled($entityId))).'.');
        }
        $mode = $asked ?? self::defaultMode($entityId)
            ?? throw new BusinessRuleViolation('No weighing mode is switched on for this mill; the SaaS owner switches them on (Weighment modes).');

        return [$mode, self::effective($entityId)['version']];
    }

    /** A paddy vehicle's mode (null for outbound and other goods). Vehicles registered before the modes keep their earlier flow. */
    public static function of(GateEntry $entry): ?string
    {
        if ($entry->direction !== 'inbound' || $entry->purpose === 'other') {
            return null;
        }

        return $entry->weigh_mode ?? self::legacy($entry->entity_id);
    }

    /** Weighed before the lab (modes 1 and 3). */
    public static function weighsFirst(?string $mode): bool
    {
        return in_array($mode, [self::LAB_BEFORE_SLIP, self::WEIGH_FIRST_LAB_LATER], true);
    }

    public static function usesLab(?string $mode): bool
    {
        return $mode !== null && $mode !== self::NO_LAB;
    }

    /** The mill's choice of default mode (Owner / Super Admin): only among the modes the SaaS owner switched on. */
    public static function chooseDefault(string $entityId, string $mode): void
    {
        if (! self::isEnabled($entityId, $mode)) {
            throw new BusinessRuleViolation('That mode is not switched on for this mill.');
        }
        app(SettingsService::class)->put(self::DEFAULT_SETTING, $mode, $entityId);
    }

    /**
     * The SaaS owner switches modes on or off for a company (its defaults) or one mill (its own setting; null modes:
     * the mill follows its company again). Vehicles already registered keep their mode.
     *
     * @param  list<string>|null  $modes
     */
    public static function configure(?string $customerId, ?string $entityId, ?array $modes, ?string $reason = null): ?WeighmentModeConfig
    {
        $actor = app(ActorContext::class)->account() ?? auth()->user();
        if (! $actor instanceof LoginAccount || ! $actor->isPlatformAdmin()) {
            throw new BusinessRuleViolation('Only the SaaS owner can switch weighing modes on or off.');
        }
        if (($customerId === null) === ($entityId === null)) {
            throw new BusinessRuleViolation('Choose a company or one mill.');
        }
        if ($modes !== null) {
            $modes = array_values(array_intersect(self::ALL, $modes));
        }
        if ($customerId === null && $modes === null) {
            // A mill back to its company's defaults.
        } elseif ($modes === null) {
            throw new BusinessRuleViolation('A company always has its own defaults.');
        }

        $result = app(TenantContext::class)->runAsSystem(function () use ($customerId, $entityId, $modes, $reason, $actor): ?WeighmentModeConfig {
            return DB::transaction(function () use ($customerId, $entityId, $modes, $reason, $actor): ?WeighmentModeConfig {
                $config = WeighmentModeConfig::query()->lockForUpdate()
                    ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId), fn ($q) => $q->where('customer_id', $customerId))->first();
                $before = $config?->enabled();
                $level = $entityId !== null ? 'mill' : 'company';
                $millCustomer = $entityId !== null ? Entity::query()->whereKey($entityId)->value('customer_id') : $customerId;
                if ($modes === null) {
                    if ($config === null) {
                        return null;
                    }
                    WeighmentModeChange::query()->create(['config_id' => $config->id, 'customer_id' => $millCustomer, 'entity_id' => $entityId,
                        'level' => $level, 'action' => 'inherit', 'before' => $before, 'after' => null, 'version' => $config->version + 1,
                        'changed_by' => $actor->id, 'reason' => $reason]);
                    $config->delete();
                    app(AuditLogger::class)->record('weighment_modes.inherit', null, null, ['entity_id' => $entityId, 'before' => $before, 'reason' => $reason]);

                    return null;
                }
                if ($config !== null && $before === $modes) {
                    return $config;   // nothing changed
                }
                $config ??= new WeighmentModeConfig(['customer_id' => $customerId, 'entity_id' => $entityId, 'version' => 0]);
                foreach (self::ALL as $m) {
                    $config->setAttribute($m, in_array($m, $modes, true));
                }
                $config->version = $config->version + 1;
                $config->updated_by = $actor->id;
                $config->save();
                WeighmentModeChange::query()->create(['config_id' => $config->id, 'customer_id' => $millCustomer, 'entity_id' => $entityId,
                    'level' => $level, 'action' => 'set', 'before' => $before, 'after' => $modes, 'version' => $config->version,
                    'changed_by' => $actor->id, 'reason' => $reason]);
                app(AuditLogger::class)->record('weighment_modes.set', null, null,
                    ['level' => $level, 'customer_id' => $customerId, 'entity_id' => $entityId, 'before' => $before, 'after' => $modes, 'reason' => $reason]);

                return $config;
            });
        });
        app(self::class)->cache = [];

        return $result;
    }

    /** Before the modes: the mill's "weigh before lab" setting (on: mode 1, off: mode 4). */
    private static function legacy(?string $entityId): string
    {
        return GatePolicy::weighBeforeLab($entityId) ? self::LAB_BEFORE_SLIP : self::LAB_FIRST;
    }
}
