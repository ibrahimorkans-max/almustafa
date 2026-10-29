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
use App\Domain\Hr\ContractorVehicles;
use App\Domain\Sales\SalesDocuments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Resources\Hr\ContractorBillResource;
use App\Filament\Support\ApprovalNotice;
use App\Filament\Support\AttachmentsPanel;
use App\Filament\Support\NegotiationActions;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\LabParameter;
use App\Models\Gate\LabTest;
use App\Models\Hr\ContractorRate;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\ActionSize;
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
        // A gate entry has many steps: small buttons so they fit, also on a phone.
        return array_map(fn ($a) => $a->size(ActionSize::Small), $this->actions());
    }

    /** @return array<int, Action|ActionGroup> */
    private function actions(): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;
        $has = fn (GateEntry $e, string $event) => GateEvent::query()->where('gate_entry_id', $e->id)->where('event', $event)->exists();

        $percent = '/^\d{1,3}(\.\d{1,2})?$/';
        $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
        $weighed = fn (GateEntry $e) => $has($e, 'gross_weighed');
        $latestDecision = fn (GateEntry $e) => NegotiationActions::labDecision($e);
        $settled = fn (GateEntry $e) => app(GateService::class)->settlementApproved($e);

        return [
            Action::make('labTest')->label(fn (GateEntry $e) => LabTest::query()->where('gate_entry_id', $e->id)->exists() ? 'Correct lab result' : 'Record lab test')->icon('heroicon-o-beaker')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'inbound' && $can('lab.test') && ! $weighed($e))
                ->modalDescription('Before the loaded weight. A result above its grace limit rejects the lot automatically. A correction replaces the result (the earlier one stays in the history) and the lab decides again.')
                ->fillForm(function (GateEntry $e): array {
                    $last = LabTest::query()->where('gate_entry_id', $e->id)->latestFirst()->first();
                    if ($last === null) {
                        return [];
                    }
                    $fill = ['sample_code' => $last->sample_code, 'grade' => $last->grade, 'remarks' => $last->remarks];
                    foreach ((array) ($last->results ?? []) as $code => $bp) {
                        $fill['r_'.$code] = rtrim(rtrim(number_format(((int) $bp) / 100, 2, '.', ''), '0'), '.');
                    }

                    return $fill;
                })
                ->form(fn (GateEntry $e): array => [
                    Textarea::make('correction_reason')->label('Why is the result corrected?')->maxLength(500)
                        ->visible(fn () => LabTest::query()->where('gate_entry_id', $e->id)->exists())
                        ->required(fn () => GateEvent::query()->where('gate_entry_id', $e->id)->whereIn('event', ['lab_accepted', 'lab_rejected'])->exists()),
                    TextInput::make('sample_code')->maxLength(40),
                    ...app(LabStandards::class)->parametersFor($e->product_id)->map(fn (LabParameter $p) => TextInput::make('r_'.$p->code)
                        ->label("{$p->name_en} ({$p->name_ur}) {$p->unit}")
                        ->helperText('Standard up to '.$pct($p->standard_bp).' · grace up to '.$pct($p->grace_bp))
                        ->regex($percent))->values()->all(),
                    TextInput::make('grade')->maxLength(20),
                    Textarea::make('remarks')->maxLength(1000),
                ])
                ->action(function (GateEntry $e, array $data): void {
                    $values = ['sample_code' => $data['sample_code'] ?? null, 'grade' => $data['grade'] ?? null, 'remarks' => $data['remarks'] ?? null,
                        'correction_reason' => $data['correction_reason'] ?? null, 'results' => []];
                    foreach ($data as $key => $value) {
                        if (str_starts_with((string) $key, 'r_') && filled($value)) {
                            $values['results'][substr((string) $key, 2)] = Units::percentToBp((string) $value);
                        }
                    }
                    $this->run(fn (GateService $g) => $g->recordLabTest($e, $values), filled($data['correction_reason'] ?? null) ? 'Lab result corrected' : 'Lab test recorded');
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
            ...NegotiationActions::prices(fn (callable $step, string $ok) => $this->run($step, $ok)),
            Action::make('unloaded')->label('Mark unloaded')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'inbound' && $can('gate.entry') && ! $has($e, 'unloaded'))
                ->modalDescription('The slip will book the net weight into this godown. Rejected bags go back on the vehicle: its empty weight then carries them, so only the paddy unloaded is accepted.')
                ->form([
                    Select::make('godown_id')->label('Unloaded into')->required()
                        ->options(fn () => Godown::query()->where('status', 'active')->orderBy('code')->get()->mapWithKeys(fn (Godown $g) => [$g->id => "{$g->code} — {$g->name}"])),
                    Select::make('contractor_party_id')->label('Unloaded by (labour contractor)')->searchable()
                        ->options(fn () => ContractorBillResource::contractors())->helperText('Paid at the contractor\'s rate (HR → Contractor rates).'),
                    Toggle::make('rejected')->label('Some paddy rejected while unloading')->live(),
                    TextInput::make('rejected_kg')->label('Rejected weight (kg)')->regex(Units::DECIMAL_3)
                        ->visible(fn (Get $get) => (bool) $get('rejected'))
                        ->required(fn (Get $get) => (bool) $get('rejected') && ($get('rejected_where') === 'off' || blank($get('rejected_bags')))),
                    TextInput::make('rejected_bags')->label('Rejected bags (if counted)')->integer()->minValue(1)
                        ->visible(fn (Get $get) => (bool) $get('rejected')),
                    Radio::make('rejected_where')->label('At the empty weighing, the rejected paddy is')->default('on')->live()
                        ->options(['on' => 'Still on the vehicle (net comes out as the paddy unloaded by itself)', 'off' => 'Taken off and kept aside (its weight is subtracted from net)'])
                        ->visible(fn (Get $get) => (bool) $get('rejected'))->required(fn (Get $get) => (bool) $get('rejected')),
                    Textarea::make('rejection_reason')->label('Why')->minLength(5)->maxLength(300)
                        ->visible(fn (Get $get) => (bool) $get('rejected'))->required(fn (Get $get) => (bool) $get('rejected')),
                ])
                ->action(fn (GateEntry $e, array $data) => $this->run(
                    fn (GateService $g) => $g->markUnloaded($e, godown: Godown::query()->findOrFail($data['godown_id']),
                        rejectedBags: ($data['rejected'] ?? false) && filled($data['rejected_bags'] ?? null) ? (int) $data['rejected_bags'] : null,
                        rejectionReason: ($data['rejected'] ?? false) ? (string) $data['rejection_reason'] : null,
                        contractor: filled($data['contractor_party_id'] ?? null) ? Party::query()->find($data['contractor_party_id']) : null,
                        rejectedKg: ($data['rejected'] ?? false) && filled($data['rejected_kg'] ?? null) ? (string) $data['rejected_kg'] : null,
                        rejectedOffVehicle: ($data['rejected'] ?? false) && ($data['rejected_where'] ?? 'on') === 'off'),
                    'Marked unloaded',
                )),
            // A vehicle belongs to one contractor: linked once, never moved to another.
            Action::make('contractor')->label('Link contractor')->icon('heroicon-o-user-group')->color('gray')
                ->visible(fn (GateEntry $e) => $can('gate.entry') && $e->contractor_party_id === null && $e->status !== 'cancelled' && ($has($e, 'unloaded') || $has($e, 'loaded')))
                ->fillForm(fn (GateEntry $e) => ['work' => $e->direction === 'outbound' ? 'loading' : 'unloading'])
                ->form([
                    Select::make('contractor_party_id')->label('Contractor')->required()->searchable()->options(fn () => ContractorBillResource::contractors())
                        ->helperText('Once linked, the vehicle stays with this contractor.'),
                    Select::make('work')->options(ContractorRate::WORK)->required(),
                ])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn () => app(ContractorVehicles::class)->assign([$e],
                    Party::query()->findOrFail($data['contractor_party_id']), (string) $data['work']), 'Contractor linked')),
            Action::make('unlinkContractor')->label('Take off contractor')->icon('heroicon-o-user-minus')->color('danger')
                ->visible(fn (GateEntry $e) => $e->contractor_party_id !== null && $e->contractor_bill_id === null && SelfApproval::allowed($this->me()))
                ->requiresConfirmation()->modalDescription('Only for a link made by mistake (before the contractor is billed). It is kept in the audit log.')
                ->action(fn (GateEntry $e) => $this->run(fn () => app(ContractorVehicles::class)->assign([$e], null), 'Contractor taken off')),
            Action::make('correctVehicle')->label('Correct vehicle number')->icon('heroicon-o-pencil-square')->color('gray')
                ->visible(fn (GateEntry $e) => $can('gate.entry') && $e->status !== 'cancelled')
                ->modalDescription('For a number typed wrong at the gate. The weights stay; the correction is kept in the timeline and the audit log.')
                ->fillForm(fn (GateEntry $e) => ['registration_no' => $e->no_plate ? null : $e->registration_no])
                ->form([
                    TextInput::make('registration_no')->label('Correct vehicle number')->required()->maxLength(20)->placeholder('MNA-21-1234'),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(300),
                ])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->correctVehicle($e, (string) $data['registration_no'], (string) $data['reason']), 'Vehicle number corrected')),
            Action::make('quarantine')->label('Hold rejected lot (quarantine)')->icon('heroicon-o-lock-closed')->color('warning')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('gate.entry') && $latestDecision($e) === 'lab_rejected'
                    && ! in_array('held_quarantine', $e->flags ?? [], true))
                ->modalDescription('While it is negotiated, the lot is kept apart. It is not stock and is not billed until accepted after negotiation.')
                ->form([
                    TextInput::make('location')->label('Where it is held')->required()->maxLength(120),
                    Textarea::make('note')->maxLength(1000),
                ])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->holdRejectedLot($e, $data['location'], $data['note'] ?? null), 'Lot held apart')),
            ...NegotiationActions::settlement(fn (callable $step, string $ok) => $this->run($step, $ok)),
            Action::make('slip')->label('Issue weighment slip')->icon('heroicon-o-document-text')->color('primary')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('weighment.capture') && ! $has($e, 'slip_issued') && $e->gross_kg !== null && $e->tare_kg !== null
                    && ($e->direction !== 'inbound' || $settled($e) || $latestDecision($e) !== 'lab_accepted'))
                ->requiresConfirmation()
                ->modalDescription('Freezes gross, tare, net and the settled Caat, books the payable weight on the sauda and into the godown, and drafts the purchase bill.')
                ->action(fn (GateEntry $e) => $this->run(fn (GateService $g) => $g->issueSlip($e), 'Slip issued')),
            PrintButton::dialog('printSlip', 'Print slip', fn (GateEntry $e) => [app(SlipRenderer::class)->pdf($e), str_replace('/', '-', (string) $e->number).'.pdf'])
                ->visible(fn (GateEntry $e) => $has($e, 'slip_issued')),
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
            PrintButton::dialog('printGatePass', 'Print gate pass', fn (GateEntry $e) => [app(SalesDocuments::class)->gatePassPdf($e), 'gate-pass-'.str_replace('/', '-', (string) $e->number).'.pdf'])
                ->visible(fn (GateEntry $e) => $has($e, 'gate_pass_issued')),
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
            ActionGroup::make([
                Action::make('token80')->label('Token slip — 80 mm roll')
                    ->url(fn (GateEntry $e) => route('filament.admin.gate.token', ['id' => $e->id, 'paper' => 'roll']), shouldOpenInNewTab: true),
                Action::make('token50')->label('Token slip — 50 mm roll')
                    ->url(fn (GateEntry $e) => route('filament.admin.gate.token', ['id' => $e->id, 'paper' => 'roll50']), shouldOpenInNewTab: true),
                Action::make('tokenA4')->label('Token — A4 paper')
                    ->url(fn (GateEntry $e) => route('filament.admin.gate.token', ['id' => $e->id, 'paper' => 'a4']), shouldOpenInNewTab: true),
                Action::make('tokenPdf')->label('Download token (PDF)')->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (GateEntry $e) => route('filament.admin.gate.token', ['id' => $e->id, 'paper' => 'a4', 'download' => 1])),
            ])->label(fn (GateEntry $e) => 'Print token '.$e->tokenLabel())->icon('heroicon-o-ticket')->color('gray')->button()
                ->visible(fn (GateEntry $e) => $e->token_no !== null || $e->token_serial !== null),
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
