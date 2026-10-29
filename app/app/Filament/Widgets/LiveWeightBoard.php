<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Gate\GateService;
use App\Domain\Gate\LiveCapture;
use App\Domain\Gate\LiveWeight;
use App\Domain\Gate\ManualWeight;
use App\Domain\Gate\QuickWeigh;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Scale;
use App\Models\Gate\Weighment;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Each weighbridge as a dial (refreshed every second): the needle and the figure show the indicator's
 * weight, the ring is green when stable, amber while moving, grey without a signal.
 *
 * Weighing is one form per weighbridge: party name, vehicle number, inbound (paddy or other goods) or
 * outbound, and one "Record weight" button that takes the weight the vehicle needs next — loaded
 * (gross) or empty (tare) — from the stable reading (QuickWeigh finds or registers the visit,
 * LiveCapture records). "Enter one weight manually" types one of the two weights with a reason and the
 * other weighbridge's slip (ManualWeight). Paddy stays lab first.
 */
class LiveWeightBoard extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $view = 'filament.widgets.live-weight-board';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    /** @var array<string, string|null> scale id => party name typed */
    public array $party = [];

    /** @var array<string, string|null> scale id => vehicle number typed */
    public array $plate = [];

    /** @var array<string, string|null> scale id => the item it carries (a product, or the goods as typed) */
    public array $item = [];

    /** @var array<string, string|null> scale id => paddy | other_in | outbound */
    public array $kind = [];

    /** @var array<string, string|null> scale id => with_jack | without_jack, for a vehicle registered here */
    public array $jack = [];

    /** @var array<string, string|null> scale id => godown a paddy lot is unloaded into */
    public array $godown = [];

    /** The weighbridge on the clock (one clock; a switch when the mill has more than one). */
    public ?string $scaleId = null;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->hasPermission('gate.entry') || $user->hasPermission('weighment.capture') || $user->hasPermission('dashboard.view'));
    }

    /** @return list<array{scale_id: string, code: string, name: string, kg: float|null, stable: bool, live: bool, age_seconds: int|null, capacity: float}> */
    public function getScales(): array
    {
        $capacity = Scale::query()->pluck('capacity_kg', 'id');

        return array_map(fn (array $s) => $s + ['capacity' => (float) ($capacity[$s['scale_id']] ?? 60000)], app(LiveWeight::class)->all());
    }

    /** @return array<string, mixed>|null the weighbridge shown on the clock */
    public function currentScale(): ?array
    {
        $scales = $this->getScales();

        return collect($scales)->firstWhere('scale_id', $this->scaleId) ?? ($scales[0] ?? null);
    }

    public function showScale(string $scaleId): void
    {
        $this->scaleId = $scaleId;
    }

    public function canRecord(): bool
    {
        return $this->can('weighment.capture');
    }

    public function canType(): bool
    {
        return $this->can('weighment.manual');
    }

    private function can(string $permission): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission($permission);
    }

    /** @return list<array{id: string, label: string, kind: string|null, token: string|null, plate: string}> vehicles waiting, in token order */
    public function getWaiting(): array
    {
        return $this->canRecord() || $this->canType() ? app(LiveCapture::class)->waiting()->all() : [];
    }

    /** @return list<string> party names for the form's suggestions */
    public function getPartyNames(): array
    {
        // "Name (CODE)": typing part of a name or a code finds it; any other name is kept as typed.
        return GateEntryResource::partySuggestions();
    }

    /** @return list<string> product names for the item suggestions */
    public function getProductNames(): array
    {
        return Product::query()->where('status', 'active')->orderBy('name')->limit(300)->pluck('name')->all();
    }

    /** @return array<string, string> */
    public function getGodowns(): array
    {
        return Godown::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** The open visit of the vehicle typed on this weighbridge's form, if any. */
    public function visitFor(string $scaleId): ?GateEntry
    {
        $plate = (string) ($this->plate[$scaleId] ?? '');

        return $plate === '' ? null : QuickWeigh::openVisit($plate);
    }

    /** @return array<string, mixed>|null the visit's weights and what it needs next */
    public function stepsFor(string $scaleId): ?array
    {
        $entry = $this->visitFor($scaleId);

        return $entry === null ? null : LiveCapture::steps($entry) + ['entry' => $entry, 'next' => LiveCapture::nextKind($entry)];
    }

    /** Typing a known vehicle fills in its party and kind. */
    public function updated(string $name): void
    {
        if (! str_starts_with($name, 'plate.')) {
            return;
        }
        $scaleId = substr($name, 6);
        $entry = $this->visitFor($scaleId);
        if ($entry !== null) {
            $this->party[$scaleId] = $entry->party->name ?? $this->party[$scaleId] ?? null;
            $this->item[$scaleId] = $entry->product->name ?? $entry->goods_description ?? $this->item[$scaleId] ?? null;
            $this->kind[$scaleId] = $entry->direction === 'outbound' ? 'outbound' : ($entry->purpose === 'other' ? 'other_in' : 'paddy');
        }
    }

    /** A waiting vehicle chosen from the list. */
    public function pick(string $scaleId, string $entryId): void
    {
        $entry = GateEntry::query()->with('party')->find($entryId);
        if ($entry !== null) {
            $this->plate[$scaleId] = $entry->registration_no;
            $this->updated('plate.'.$scaleId);
        }
    }

    /** The single "Record weight" button: the weight the vehicle needs next, from the stable reading. */
    public function recordWeight(string $scaleId): void
    {
        abort_unless($this->canRecord(), 403);
        $scale = Scale::query()->find($scaleId);
        if ($scale === null) {
            return;
        }
        try {
            [$entry, $new] = app(QuickWeigh::class)->visit((string) ($this->party[$scaleId] ?? ''), (string) ($this->plate[$scaleId] ?? ''),
                (string) ($this->kind[$scaleId] ?? 'paddy'), $this->item[$scaleId] ?? null, $this->jack[$scaleId] ?? null);
            $kind = LiveCapture::nextKind($entry);
            if ($kind === null) {
                $token = $entry->token_no !== null || $entry->token_serial !== null ? 'Token '.$entry->tokenLabel().' · ' : '';
                Notification::make()->title($token.$entry->vehicleLabel().($new ? ' registered.' : '.'))
                    ->body($this->waitingReason($entry))->warning()->actions($new ? self::tokenActions($entry) : [])->duration($new ? 'persistent' : 6000)->send();

                return;
            }
            $godownId = $this->godown[$scaleId] ?? null;
            $weighment = app(LiveCapture::class)->record($entry, $scale, $kind, $godownId ? Godown::query()->find($godownId) : null);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        $entry->refresh();
        Notification::make()->success()->actions($new ? self::tokenActions($entry) : [])->duration($new ? 'persistent' : 6000)
            ->title(ucfirst(ManualWeight::label($entry, $weighment->kind)).' '.number_format((float) $weighment->weight_kg, 0).' kg recorded'
                .($entry->token_no !== null || $entry->token_serial !== null ? ' — token '.$entry->tokenLabel() : '').' · '.$entry->vehicleLabel())
            ->body($entry->net_kg !== null ? 'Net weight '.number_format((float) $entry->net_kg, 0).' kg.' : 'Next: the '.$this->nextLabel($entry).'.')->send();
        $this->godown[$scaleId] = null;
        if ($entry->net_kg !== null) {
            $this->plate[$scaleId] = $this->party[$scaleId] = $this->item[$scaleId] = null;
        }
    }

    /**
     * "Print token" on the 80 mm or the 50 mm roll, offered when a vehicle is registered here.
     *
     * @return list<NotificationAction>
     */
    public static function tokenActions(GateEntry $entry): array
    {
        return [
            NotificationAction::make('token80')->label('Print token (80 mm)')->button()
                ->url(route('filament.admin.gate.token', ['id' => $entry->id, 'paper' => 'roll']), shouldOpenInNewTab: true),
            NotificationAction::make('token50')->label('Print token (50 mm)')->button()->color('gray')
                ->url(route('filament.admin.gate.token', ['id' => $entry->id, 'paper' => 'roll50']), shouldOpenInNewTab: true),
        ];
    }

    /** Why the vehicle cannot be weighed now. */
    public function waitingReason(GateEntry $entry): string
    {
        return match (true) {
            $entry->direction === 'inbound' && $entry->purpose !== 'other' && in_array($entry->status, ['arrived', 'lab_rejected'], true) => $entry->status === 'lab_rejected' ? 'The lab rejected this lot (see Negotiation).' : 'Waiting for the lab: weigh it after the lab accepts the lot.',
            $entry->direction === 'outbound' && $entry->status === 'tare_weighed' && $entry->purpose !== 'other' => 'Mark it loaded on the vehicle page, then record the loaded weight.',
            $entry->net_kg !== null => 'Both weights are recorded (net '.number_format((float) $entry->net_kg, 0).' kg).',
            default => 'It does not need a weight now (status: '.str_replace('_', ' ', $entry->status).').',
        };
    }

    public function nextLabel(GateEntry $entry): string
    {
        $next = LiveCapture::nextKind($entry);

        return $next === null ? 'next step on the vehicle page' : ManualWeight::label($entry, $next);
    }

    /** "Enter one weight manually": one of the two weights typed, with a reason and the other weighbridge's slip. */
    public function typeWeightAction(): Action
    {
        return Action::make('typeWeight')->label('Enter one weight manually')->icon('heroicon-o-pencil-square')->color('gray')
            ->visible(fn () => $this->canType())
            ->modalHeading(fn (array $arguments) => 'Enter one weight manually'.(($p = $this->plate[$arguments['scale'] ?? ''] ?? null) ? ' — '.$p : ''))
            ->modalDescription('Only one of the two weights may be typed; the other must come from this weighbridge. It counts at once, is marked "typed by hand" and goes to the weight checks.')
            ->form([
                Radio::make('kind')->label('Which weight')->required()->default('gross')
                    ->options(['gross' => 'Loaded weight (gross)', 'tare' => 'Empty weight (tare)']),
                TextInput::make('weight_kg')->label('Weight (kg)')->required()->regex('/^\d{1,9}(\.\d{1,3})?$/')->placeholder('15240')->suffix('kg'),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)
                    ->placeholder('e.g. empty weight from Kanta 2, slip no. 4512'),
                FileUpload::make('slip')->label('Slip or evidence (photo or PDF, optional)')->storeFiles(false)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(5120),
            ])
            ->action(function (array $data, array $arguments): void {
                $scaleId = (string) ($arguments['scale'] ?? '');
                $godownId = $this->godown[$scaleId] ?? null;
                $godown = $godownId ? Godown::query()->find($godownId) : null;
                $file = $data['slip'] ?? null;
                if (is_array($file)) {
                    $file = reset($file) ?: null;
                }
                try {
                    // Registering the visit, unloading (paddy, before the empty weight) and the typed weight succeed or fail together.
                    [$entry, $w] = DB::transaction(function () use ($scaleId, $data, $godown, $file): array {
                        [$entry] = app(QuickWeigh::class)->visit((string) ($this->party[$scaleId] ?? ''), (string) ($this->plate[$scaleId] ?? ''),
                            (string) ($this->kind[$scaleId] ?? 'paddy'), $this->item[$scaleId] ?? null, $this->jack[$scaleId] ?? null);
                        if ($data['kind'] === Weighment::TARE && LiveCapture::steps($entry)['unload']) {
                            if ($godown === null) {
                                throw new BusinessRuleViolation('Choose the godown it was unloaded into, then enter the empty weight.');
                            }
                            app(GateService::class)->markUnloaded($entry, godown: $godown);
                            $entry->refresh();
                        }
                        if (LiveCapture::nextKind($entry) !== $data['kind']) {
                            throw new BusinessRuleViolation('This vehicle needs the '.$this->nextLabel($entry).' now. '.(LiveCapture::nextKind($entry) === null ? $this->waitingReason($entry) : ''));
                        }

                        return [$entry, app(ManualWeight::class)->record($entry, (string) $data['kind'], (string) $data['weight_kg'], (string) $data['reason'],
                            $file instanceof TemporaryUploadedFile ? (string) $file->get() : null)];
                    });
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                $entry->refresh();
                Notification::make()->success()
                    ->title(ucfirst(ManualWeight::label($entry, $w->kind)).' '.number_format((float) $w->weight_kg, 0).' kg entered · '.$entry->vehicleLabel())
                    ->body(($entry->net_kg !== null ? 'Net weight '.number_format((float) $entry->net_kg, 0).' kg. ' : '').'Marked "typed by hand" for the weight checks.')->send();
            });
    }
}
