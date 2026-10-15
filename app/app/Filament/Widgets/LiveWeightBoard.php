<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Gate\LiveCapture;
use App\Domain\Gate\LiveWeight;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Scale;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

/**
 * Each weighbridge's reading as it changes (refreshed every second): the weight, "stable" when the
 * indicator has settled, "moving" while a truck rolls on, "no signal" when the gate agent stopped
 * sending. With weighment.capture, the operator picks the vehicle on the platform and records its
 * gross or tare weight once the reading is stable (LiveCapture); every such weight goes to the
 * Super Admin's weight checks.
 */
class LiveWeightBoard extends Widget
{
    protected static string $view = 'filament.widgets.live-weight-board';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    /** @var array<string, string|null> scale id => the gate entry chosen for it */
    public array $selected = [];

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->hasPermission('gate.entry') || $user->hasPermission('weighment.capture') || $user->hasPermission('dashboard.view'));
    }

    /** @return list<array{scale_id: string, code: string, name: string, kg: float|null, stable: bool, live: bool, age_seconds: int|null}> */
    public function getScales(): array
    {
        return app(LiveWeight::class)->all();
    }

    public function canRecord(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('weighment.capture');
    }

    /** @return list<array{id: string, label: string, kind: string}> */
    public function getWaiting(): array
    {
        return $this->canRecord() ? app(LiveCapture::class)->waiting()->all() : [];
    }

    public function recordWeight(string $scaleId): void
    {
        abort_unless($this->canRecord(), 403);
        $entryId = $this->selected[$scaleId] ?? null;
        $scale = Scale::query()->find($scaleId);
        $entry = $entryId === null || $entryId === '' ? null : GateEntry::query()->find($entryId);
        if ($scale === null || $entry === null) {
            Notification::make()->title('Choose the vehicle on the platform first.')->warning()->send();

            return;
        }
        try {
            $weighment = app(LiveCapture::class)->record($entry, $scale);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        $this->selected[$scaleId] = null;
        Notification::make()->success()
            ->title(ucfirst($weighment->kind).' '.number_format((float) $weighment->weight_kg, 0).' kg recorded for '.$entry->registration_no.'.')
            ->body('It is waiting for the Super Admin\'s weight check.')->send();
    }
}
