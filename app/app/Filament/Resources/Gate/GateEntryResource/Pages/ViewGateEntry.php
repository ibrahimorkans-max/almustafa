<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\GateEntryResource\Pages;

use App\Domain\Approvals\Actions\ManualWeighmentOverride;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Approvals\SelfApproval;
use App\Domain\Gate\GateService;
use App\Domain\Gate\LabStandards;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Sales\SalesDocuments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Support\ApprovalNotice;
use App\Filament\Support\AttachmentsPanel;
use App\Filament\Support\Units;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\GatePriceVersion;
use App\Models\Gate\LabParameter;
use App\Models\Gate\LabTest;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

/**
 * One vehicle visit. Weights are NOT typed here: they arrive from the weighbridge indicator
 * through the gate agent. The only manual path is an Owner override request.
 */
class ViewGateEntry extends ViewRecord
{
    protected static string $resource = GateEntryResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;
        $has = fn (GateEntry $e, string $event) => GateEvent::query()->where('gate_entry_id', $e->id)->where('event', $event)->exists();

        $percent = '/^\d{1,3}(\.\d{1,2})?$/';
        $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
        $weighed = fn (GateEntry $e) => $has($e, 'gross_weighed');
        $latestDecision = fn (GateEntry $e) => GateEvent::query()->where('gate_entry_id', $e->id)->whereIn('event', ['lab_accepted', 'lab_rejected'])
            ->newestFirst()->value('event');
        $pendingPrice = fn (GateEntry $e) => GatePriceVersion::query()->where('gate_entry_id', $e->id)->where('status', GatePriceVersion::PROPOSED)->orderByDesc('version')->first();
        $settled = fn (GateEntry $e) => app(GateService::class)->settlementApproved($e);
        $proposal = fn (GateEntry $e) => GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'settlement_proposed')->newestFirst()->first();

        return [
            Action::make('labTest')->label('Record lab test')->icon('heroicon-o-beaker')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'inbound' && $can('lab.test') && ! $weighed($e))
                ->modalDescription('Before any weighing. A result above its grace limit rejects the lot automatically.')
                ->form(fn (GateEntry $e): array => [
                    TextInput::make('sample_code')->maxLength(40),
                    ...app(LabStandards::class)->parametersFor($e->product_id)->map(fn (LabParameter $p) => TextInput::make('r_'.$p->code)
                        ->label("{$p->name_en} ({$p->name_ur}) {$p->unit}")
                        ->helperText('Standard up to '.$pct($p->standard_bp).' · grace up to '.$pct($p->grace_bp))
                        ->regex($percent))->values()->all(),
                    TextInput::make('grade')->maxLength(20),
                    Textarea::make('remarks')->maxLength(1000),
                ])
                ->action(function (GateEntry $e, array $data): void {
                    $values = ['sample_code' => $data['sample_code'] ?? null, 'grade' => $data['grade'] ?? null, 'remarks' => $data['remarks'] ?? null, 'results' => []];
                    foreach ($data as $key => $value) {
                        if (str_starts_with((string) $key, 'r_') && filled($value)) {
                            $values['results'][substr((string) $key, 2)] = Units::percentToBp((string) $value);
                        }
                    }
                    $this->run(fn (GateService $g) => $g->recordLabTest($e, $values), 'Lab test recorded');
                }),
            Action::make('accept')->label('Lab: accept')->color('success')->icon('heroicon-o-check')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('lab.decide') && ! $weighed($e) && LabTest::query()->where('gate_entry_id', $e->id)->exists()
                    && app(LabStandards::class)->overGrace($e) === [])
                ->form([Textarea::make('note')->label('Note (required if above a reject limit)')->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->decideLab($e, true, $data['note'] ?? null), 'Lot accepted')),
            Action::make('reject')->label('Lab: reject')->color('danger')->icon('heroicon-o-x-mark')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('lab.decide') && ! $weighed($e) && LabTest::query()->where('gate_entry_id', $e->id)->exists()
                    && $latestDecision($e) !== 'lab_rejected')
                ->form([Textarea::make('note')->required()->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->decideLab($e, false, $data['note']), 'Lot rejected')),
            Action::make('proposePrice')->label('Propose price')->icon('heroicon-o-currency-rupee')->color('gray')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'inbound' && $can('purchase.negotiate') && ! $settled($e)
                    && LabTest::query()->where('gate_entry_id', $e->id)->exists())
                ->modalDescription(fn (GateEntry $e) => 'Offered (sauda) price: '.Units::paiseToRupees(app(GateService::class)->offeredPricePaise($e))
                    .' per maund. Last accepted: '.Units::paiseToRupees(app(GateService::class)->acceptedPrice($e)?->price_paise_per_maund)
                    .'. A new price is a new version; it is accepted by someone else.')
                ->form([
                    TextInput::make('price')->label('Price (Rs per maund)')->required()->regex(Units::DECIMAL_2),
                    Textarea::make('reason')->label('Reason (why this price; required for a lower or revised price)')->maxLength(1000),
                ])
                ->action(fn (GateEntry $e, array $data) => $this->run(
                    fn (GateService $g) => $g->proposePrice($e, Units::rupeesToPaise($data['price']), $data['reason'] ?? null), 'Price proposed')),
            Action::make('acceptPrice')->label('Accept price')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('purchase.approve') && $pendingPrice($e) !== null && ! $settled($e))
                ->modalDescription(fn (GateEntry $e) => ($p = $pendingPrice($e)) ? 'Version '.$p->version.': '.Units::paiseToRupees($p->price_paise_per_maund)
                    .' per maund'.($p->reason ? ' — '.$p->reason : '').'.' : null)
                ->form([Textarea::make('note')->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->decidePrice($e, true, $data['note'] ?? null), 'Price accepted')),
            Action::make('rejectPrice')->label('Reject price')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('purchase.approve') && $pendingPrice($e) !== null && ! $settled($e))
                ->form([Textarea::make('note')->label('Reason')->required()->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->decidePrice($e, false, $data['note']), 'Price rejected')),
            Action::make('acceptRejected')->label('Accept rejected lot (after negotiation)')->icon('heroicon-o-shield-check')->color('warning')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('purchase.approve') && $latestDecision($e) === 'lab_rejected')
                ->modalDescription('Only with a price agreed after the rejection. The lot is then weighed and received like an accepted one; the reason is kept.')
                ->form([Textarea::make('reason')->required()->minLength(10)->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->acceptRejectedLot($e, $data['reason']), 'Rejected lot accepted after negotiation')),
            Action::make('unloaded')->label('Mark unloaded')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'inbound' && $can('gate.entry') && ! $has($e, 'unloaded'))
                ->modalDescription('The slip will book the net weight into this godown.')
                ->form([
                    Select::make('godown_id')->label('Unloaded into')->required()
                        ->options(fn () => Godown::query()->where('status', 'active')->orderBy('code')->get()->mapWithKeys(fn (Godown $g) => [$g->id => "{$g->code} — {$g->name}"])),
                ])
                ->action(fn (GateEntry $e, array $data) => $this->run(
                    fn (GateService $g) => $g->markUnloaded($e, godown: Godown::query()->findOrFail($data['godown_id'])),
                    'Marked unloaded',
                )),
            Action::make('quarantine')->label('Hold rejected lot (quarantine)')->icon('heroicon-o-lock-closed')->color('warning')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('gate.entry') && $latestDecision($e) === 'lab_rejected'
                    && ! in_array('held_quarantine', $e->flags ?? [], true))
                ->modalDescription('While it is negotiated, the lot is kept apart. It is not stock and is not billed until accepted after negotiation.')
                ->form([
                    TextInput::make('location')->label('Where it is held')->required()->maxLength(120),
                    Textarea::make('note')->maxLength(1000),
                ])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->holdRejectedLot($e, $data['location'], $data['note'] ?? null), 'Lot held apart')),
            Action::make('reopenSettlement')->label('Reopen settlement')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('purchase.approve') && $settled($e) && ! $has($e, 'slip_issued'))
                ->modalDescription('Before the slip only. The Caat and the price can then be proposed and approved again. The reason is kept.')
                ->form([Textarea::make('reason')->required()->minLength(10)->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->reopenSettlement($e, $data['reason']), 'Settlement reopened')),
            Action::make('proposeSettlement')->label('Caat & price settlement')->icon('heroicon-o-calculator')->color('primary')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'inbound' && $can('purchase.negotiate') && $e->tare_kg !== null && $e->gross_kg !== null
                    && ! $settled($e) && $latestDecision($e) === 'lab_accepted')
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
                ->action(fn (GateEntry $e, array $data) => $this->run(
                    fn (GateService $g) => $g->proposeSettlement($e, (string) $data['waste_kg'], (string) $data['per_maund_kg'], $data['note'] ?? null), 'Settlement proposed')),
            Action::make('approveSettlement')->label('Approve settlement')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('purchase.approve') && ! $settled($e) && $proposal($e) !== null)
                ->modalDescription(function (GateEntry $e) use ($proposal): ?string {
                    $d = $proposal($e)?->data;

                    return $d === null ? null : 'Net '.$d['net_kg'].' kg − Waste Caat '.$d['waste_caat_kg'].' kg − Caat per Maund '.$d['per_maund_caat_kg']
                        .' kg ('.$d['per_maund_rate_kg'].' kg × maunds) = payable '.$d['payable_kg'].' kg ('.Units::kgToMaund((string) $d['payable_kg']).' maund), at '
                        .Units::paiseToRupees((int) $d['price_paise_per_maund']).' per maund'
                        .(! empty($d['rate_cut_paise_per_maund']) ? ' less the quality rate cut '.Units::paiseToRupees((int) $d['rate_cut_paise_per_maund']).' per maund' : '')
                        .'. Then the slip books this into stock and the purchase bill.';
                })
                ->form([Textarea::make('note')->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->approveSettlement($e, $data['note'] ?? null), 'Settlement approved')),
            Action::make('slip')->label('Issue weighment slip')->icon('heroicon-o-document-text')->color('primary')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('weighment.capture') && ! $has($e, 'slip_issued') && $e->gross_kg !== null && $e->tare_kg !== null
                    && ($e->direction !== 'inbound' || $settled($e) || $latestDecision($e) !== 'lab_accepted'))
                ->requiresConfirmation()
                ->modalDescription('Freezes gross, tare, net and the settled Caat, books the payable weight on the sauda and into the godown, and drafts the purchase bill.')
                ->action(fn (GateEntry $e) => $this->run(fn (GateService $g) => $g->issueSlip($e), 'Slip issued')),
            Action::make('download')->label('Download slip (PDF)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (GateEntry $e) => $has($e, 'slip_issued'))
                ->action(fn (GateEntry $e) => response()->streamDownload(
                    fn () => print app(SlipRenderer::class)->pdf($e),
                    str_replace('/', '-', (string) $e->number).'.pdf',
                    ['Content-Type' => 'application/pdf'],
                )),
            Action::make('loaded')->label('Mark loaded')->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'outbound' && $e->dispatch_order_id !== null && $can('dispatch.manage') && ! $has($e, 'loaded'))
                ->modalDescription('Loaded from the dispatch order\'s godown. Stock leaves the godown when the slip is issued.')
                ->form([TextInput::make('bag_count')->label('Bags loaded')->integer()->minValue(0)])
                ->action(fn (GateEntry $e, array $data) => $this->run(
                    fn (GateService $g) => $g->markLoaded($e, filled($data['bag_count'] ?? null) ? (int) $data['bag_count'] : null),
                    'Marked loaded',
                )),
            Action::make('gatePass')->label('Issue gate pass')->icon('heroicon-o-ticket')->color('primary')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'outbound' && $e->dispatch_order_id !== null
                    && $can('gatepass.issue') && $has($e, 'slip_issued') && ! $has($e, 'gate_pass_issued'))
                ->requiresConfirmation()
                ->modalDescription('Needs the posted tax invoice. The barrier lets the vehicle out only with a gate pass.')
                ->action(fn (GateEntry $e) => $this->run(fn (GateService $g) => $g->issueGatePass($e), 'Gate pass issued')),
            Action::make('gatePassPdf')->label('Gate pass (PDF)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (GateEntry $e) => $has($e, 'gate_pass_issued'))
                ->action(fn (GateEntry $e) => response()->streamDownload(
                    fn () => print app(SalesDocuments::class)->gatePassPdf($e),
                    'gate-pass-'.str_replace('/', '-', (string) $e->number).'.pdf',
                    ['Content-Type' => 'application/pdf'],
                )),
            Action::make('exit')->label('Vehicle left')->icon('heroicon-o-arrow-right-start-on-rectangle')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('gate.entry'))
                ->requiresConfirmation()
                ->action(fn (GateEntry $e) => $this->run(fn (GateService $g) => $g->exit($e), 'Exit recorded')),
            AttachmentsPanel::action(),
            ActionGroup::make([
                Action::make('manualWeight')->label('Enter manual weight (Owner / Super Admin)')->icon('heroicon-o-exclamation-triangle')->color('danger')
                    ->visible(fn (GateEntry $e) => $e->isOpen() && SelfApproval::allowed($this->me()) && $can('weighment.override'))
                    ->modalDescription('Only when the indicator cannot be read. Needs a reason; the weight stays marked as manual and goes to the weight checks.')
                    ->form([
                        Select::make('kind')->options(['gross' => 'Gross', 'tare' => 'Tare'])->required(),
                        TextInput::make('weight_kg')->label('Weight (kg)')->required()->regex(Units::DECIMAL_3),
                        Textarea::make('reason')->required()->minLength(10)->maxLength(1000),
                    ])
                    ->action(function (GateEntry $e, array $data): void {
                        try {
                            $request = app(ApprovalService::class)->submit(ManualWeighmentOverride::KEY, $data + ['gate_entry_id' => $e->id],
                                "Manual {$data['kind']} {$data['weight_kg']} kg for {$e->registration_no} ({$e->number})", $data['reason']);
                        } catch (ApprovalDenied|ValidationException $ex) {
                            Notification::make()->title('Not submitted')->body($ex instanceof ValidationException ? implode(' ', $ex->validator->errors()->all()) : $ex->getMessage())->danger()->send();

                            return;
                        }
                        ApprovalNotice::send($request, 'Sent for approval');
                    }),
                Action::make('cancel')->label('Cancel entry')->color('danger')
                    ->visible(fn (GateEntry $e) => $e->isOpen() && $can('gate.entry'))
                    ->form([Textarea::make('reason')->required()->maxLength(1000)])
                    ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->cancel($e, $data['reason']), 'Entry cancelled')),
            ]),
        ];
    }

    private function me(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }

    /** @param  callable(GateService): mixed  $callback */
    private function run(callable $callback, string $success): void
    {
        try {
            $callback(app(GateService::class));
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        $this->record = $this->record->fresh();
        Notification::make()->title($success)->success()->send();
    }
}
