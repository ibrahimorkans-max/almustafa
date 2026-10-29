<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Gate\GateService;
use App\Domain\Gate\LabReport;
use App\Domain\Gate\NegotiationTimeline;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Support\NegotiationActions;
use App\Filament\Support\Units;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\GatePriceVersion;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Negotiation, on its own page: the inbound vehicles whose price or Caat is being settled (in token
 * order), and for the chosen one its lab report, the price versions and Caat proposals with their
 * decisions in one timeline (when, who, why), and the buttons to propose, accept or reject the next step.
 */
class NegotiationPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Negotiation';

    protected static ?string $title = 'Negotiation';

    protected static ?string $slug = 'negotiation';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.negotiation';

    #[Url]
    public ?string $vehicle = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount
            && ($user->hasPermission('purchase.negotiate') || $user->hasPermission('purchase.approve') || $user->hasPermission('lab.decide'));
    }

    /**
     * Inbound vehicles inside the mill whose price or Caat is open, in token order.
     *
     * @return list<array{id: string, token: string|null, vehicle: string, party: string, stage: string, price: string}>
     */
    public function vehicles(): array
    {
        $gate = app(GateService::class);

        return GateEntry::query()->with('party')->where('direction', 'inbound')
            ->whereNotIn('status', ['exited', 'cancelled', 'slip_issued'])
            ->where(fn ($q) => $q->where('status', 'lab_rejected')
                ->orWhereIn('id', GatePriceVersion::query()->select('gate_entry_id'))
                ->orWhereIn('id', GateEvent::query()->where('event', 'settlement_proposed')->select('gate_entry_id'))
                ->orWhereIn('status', ['tare_weighed', 'settlement_approved']))
            ->orderBy('token_date')->orderBy('token_no')->orderBy('arrived_at')->limit(100)->get()
            ->map(function (GateEntry $e) use ($gate): array {
                $pending = NegotiationActions::pendingPrice($e);
                $stage = match (true) {
                    $gate->settlementApproved($e) => 'Settled — slip can be issued',
                    NegotiationActions::proposal($e) !== null => 'Caat proposed — waiting for approval',
                    $pending !== null => 'Price v'.$pending->version.' waiting for a decision',
                    NegotiationActions::labDecision($e) === 'lab_rejected' => 'Rejected by the lab',
                    $e->tare_kg !== null => 'Weighed — Caat to propose',
                    default => 'In negotiation',
                };
                $price = $gate->acceptedPrice($e)->price_paise_per_maund ?? $gate->offeredPricePaise($e);

                return [
                    'id' => $e->id, 'token' => $e->token_serial !== null || $e->token_no !== null ? $e->tokenLabel() : null, 'vehicle' => $e->vehicleLabel(), 'party' => $e->partyLabel() ?? '—',
                    'stage' => $stage, 'price' => $price !== null ? Units::paiseToRupees($price).' / maund' : '—',
                ];
            })->all();
    }

    public function entry(): ?GateEntry
    {
        return $this->vehicle === null ? null : GateEntry::query()->with(['party', 'product', 'sauda'])->find($this->vehicle);
    }

    public function choose(string $id): void
    {
        $this->vehicle = $id;
    }

    /** @return list<array<string, mixed>> */
    public function timeline(): array
    {
        $entry = $this->entry();

        return $entry === null ? [] : NegotiationTimeline::for($entry);
    }

    /** @return array<string, mixed>|null */
    public function labReport(): ?array
    {
        $entry = $this->entry();

        return $entry === null ? null : LabReport::for($entry);
    }

    protected function getHeaderActions(): array
    {
        $run = function (callable $step, string $ok): void {
            try {
                $step(app(GateService::class));
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();

                return;
            }
            Notification::make()->title($ok)->success()->send();
        };
        $actions = [...NegotiationActions::prices($run), ...NegotiationActions::settlement($run)];
        foreach ($actions as $action) {
            // Each button acts on the vehicle chosen on this page.
            $action->record(fn () => $this->entry())->hidden(fn () => $this->entry() === null);
        }
        $actions[] = Action::make('openVehicle')->label('Open vehicle page')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
            ->hidden(fn () => $this->entry() === null)
            ->url(fn () => $this->entry() ? GateEntryResource::getUrl('view', ['record' => $this->entry()]) : null);

        return $actions;
    }
}
