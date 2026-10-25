<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Gate\GateService;
use App\Domain\Gate\LabReport;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\GatePriceVersion;
use App\Models\Gate\LabTest;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * The negotiation buttons — price versions, taking in a rejected lot, the Caat and price settlement —
 * shared by a vehicle's page and the Negotiation page, so both follow the same rules. Each step is
 * kept with its time, who did it and why (the negotiation timeline).
 */
final class NegotiationActions
{
    /**
     * @param  callable(callable(GateService): mixed, string): void  $run  runs a step and reports the outcome
     * @return list<Action>
     */
    public static function prices(callable $run): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;

        return [
            Action::make('proposePrice')->label('Propose price')->icon('heroicon-o-currency-rupee')->color('gray')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'inbound' && $can('purchase.negotiate') && ! self::settled($e)
                    && LabTest::query()->where('gate_entry_id', $e->id)->exists())
                ->modalDescription(fn (GateEntry $e) => 'Offered (sauda) price: '.Units::paiseToRupees(app(GateService::class)->offeredPricePaise($e))
                    .' per maund. Last accepted: '.Units::paiseToRupees(app(GateService::class)->acceptedPrice($e)?->price_paise_per_maund)
                    .'. A new price is a new version; it is accepted by someone else.')
                ->form([
                    TextInput::make('price')->label('Price (Rs per maund)')->required()->regex(Units::DECIMAL_2),
                    Textarea::make('reason')->label('Reason (why this price; required for a lower or revised price)')->maxLength(1000),
                ])
                ->action(fn (GateEntry $e, array $data) => $run(
                    fn (GateService $g) => $g->proposePrice($e, Units::rupeesToPaise($data['price']), $data['reason'] ?? null), 'Price proposed')),
            Action::make('acceptPrice')->label('Accept price')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('purchase.approve') && self::pendingPrice($e) !== null && ! self::settled($e))
                ->modalDescription(fn (GateEntry $e) => ($p = self::pendingPrice($e)) ? 'Version '.$p->version.': '.Units::paiseToRupees($p->price_paise_per_maund)
                    .' per maund'.($p->reason ? ' — '.$p->reason : '').'.' : null)
                ->form([Textarea::make('note')->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $run(fn (GateService $g) => $g->decidePrice($e, true, $data['note'] ?? null), 'Price accepted')),
            Action::make('rejectPrice')->label('Reject price')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('purchase.approve') && self::pendingPrice($e) !== null && ! self::settled($e))
                ->form([Textarea::make('note')->label('Reason')->required()->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $run(fn (GateService $g) => $g->decidePrice($e, false, $data['note']), 'Price rejected')),
            Action::make('acceptRejected')->label('Accept rejected lot (after negotiation)')->icon('heroicon-o-shield-check')->color('warning')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('purchase.approve') && self::labDecision($e) === 'lab_rejected')
                ->modalDescription('Only with a price agreed after the rejection. The lot is then weighed and received like an accepted one; the reason is kept.')
                ->form([Textarea::make('reason')->required()->minLength(10)->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $run(fn (GateService $g) => $g->acceptRejectedLot($e, $data['reason']), 'Rejected lot accepted after negotiation')),
        ];
    }

    /**
     * @param  callable(callable(GateService): mixed, string): void  $run
     * @return list<Action>
     */
    public static function settlement(callable $run): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;
        $has = fn (GateEntry $e, string $event) => GateEvent::query()->where('gate_entry_id', $e->id)->where('event', $event)->exists();

        return [
            Action::make('reopenSettlement')->label('Reopen settlement')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('purchase.approve') && self::settled($e) && ! $has($e, 'slip_issued'))
                ->modalDescription('Before the slip only. The Caat and the price can then be proposed and approved again. The reason is kept.')
                ->form([Textarea::make('reason')->required()->minLength(10)->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $run(fn (GateService $g) => $g->reopenSettlement($e, $data['reason']), 'Settlement reopened')),
            Action::make('proposeSettlement')->label('Caat & price settlement')->icon('heroicon-o-calculator')->color('primary')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'inbound' && $can('purchase.negotiate') && $e->tare_kg !== null && $e->gross_kg !== null
                    && ! self::settled($e) && self::labDecision($e) === 'lab_accepted')
                ->modalDescription(fn (GateEntry $e) => 'Net '.number_format((float) $e->net_kg, 3).' kg ('.Units::kgToMaund($e->net_kg).' maund). '
                    .'Payable weight = net − Waste Caat − (Caat per Maund × net maunds); stock receives the payable weight. '
                    .'Price: '.Units::paiseToRupees(app(GateService::class)->acceptedPrice($e)->price_paise_per_maund ?? app(GateService::class)->offeredPricePaise($e))
                    .' per maund (last accepted, else the sauda rate). Approved by someone else.')
                ->fillForm(fn (GateEntry $e) => ['waste_kg' => (string) ($e->deduction_kg ?? '0'), 'per_maund_kg' => '0'])
                ->form([
                    TextInput::make('waste_kg')->label('Waste Caat (kg)')->helperText('Unusable material; starts at the deduction the lab rules give.')->required()->regex(Units::DECIMAL_3),
                    TextInput::make('per_maund_kg')->label('Caat per Maund (kg per maund)')->required()->regex(Units::DECIMAL_3),
                    Textarea::make('note')->maxLength(1000),
                ])
                ->action(fn (GateEntry $e, array $data) => $run(
                    fn (GateService $g) => $g->proposeSettlement($e, (string) $data['waste_kg'], (string) $data['per_maund_kg'], $data['note'] ?? null), 'Settlement proposed')),
            Action::make('approveSettlement')->label('Approve settlement')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('purchase.approve') && ! self::settled($e) && self::proposal($e) !== null)
                ->modalDescription(function (GateEntry $e): ?string {
                    $d = self::proposal($e)?->data;

                    return $d === null ? null : 'Net '.$d['net_kg'].' kg − Waste Caat '.$d['waste_caat_kg'].' kg − Caat per Maund '.$d['per_maund_caat_kg']
                        .' kg ('.$d['per_maund_rate_kg'].' kg × maunds) = payable '.$d['payable_kg'].' kg ('.Units::kgToMaund((string) $d['payable_kg']).' maund), at '
                        .Units::paiseToRupees((int) $d['price_paise_per_maund']).' per maund'
                        .(! empty($d['rate_cut_paise_per_maund']) ? ' less the quality rate cut '.Units::paiseToRupees((int) $d['rate_cut_paise_per_maund']).' per maund' : '')
                        .'. Then the slip books this into stock and the purchase bill.';
                })
                ->form([Textarea::make('note')->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $run(fn (GateService $g) => $g->approveSettlement($e, $data['note'] ?? null), 'Settlement approved')),
        ];
    }

    /** The lab decision that counts now (a corrected result clears the earlier ones): lab_accepted, lab_rejected or null. */
    public static function labDecision(GateEntry $e): ?string
    {
        return match (LabReport::for($e)['decision']) {
            'accepted' => 'lab_accepted', 'rejected' => 'lab_rejected', default => null,
        };
    }

    public static function pendingPrice(GateEntry $e): ?GatePriceVersion
    {
        return GatePriceVersion::query()->where('gate_entry_id', $e->id)->where('status', GatePriceVersion::PROPOSED)->orderByDesc('version')->first();
    }

    public static function settled(GateEntry $e): bool
    {
        return app(GateService::class)->settlementApproved($e);
    }

    public static function proposal(GateEntry $e): ?GateEvent
    {
        return GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'settlement_proposed')->newestFirst()->first();
    }
}
