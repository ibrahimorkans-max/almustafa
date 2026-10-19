<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\GateEntryResource\Pages;

use App\Domain\Approvals\Actions\ManualWeighmentOverride;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Approvals\SelfApproval;
use App\Domain\Gate\GateService;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Sales\SalesDocuments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Support\ApprovalNotice;
use App\Filament\Support\Units;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
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

        $percent = fn (string $name, string $label) => TextInput::make($name)->label($label.' (%)')->regex('/^\d{1,2}(\.\d{1,2})?$/');

        return [
            Action::make('labTest')->label('Record lab test')->icon('heroicon-o-beaker')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $e->direction === 'inbound' && $can('lab.test'))
                ->form([
                    TextInput::make('sample_code')->maxLength(40),
                    $percent('moisture', 'Moisture'), $percent('foreign_matter', 'Foreign matter'), $percent('broken', 'Broken'),
                    $percent('damaged', 'Damaged / discoloured'), $percent('immature', 'Immature'), $percent('chalky', 'Chalky'),
                    TextInput::make('grade')->maxLength(20),
                    Textarea::make('remarks')->maxLength(1000),
                ])
                ->action(function (GateEntry $e, array $data): void {
                    $values = ['sample_code' => $data['sample_code'] ?? null, 'grade' => $data['grade'] ?? null, 'remarks' => $data['remarks'] ?? null];
                    foreach (LabTest::PARAMETERS as $param => $column) {
                        $values[$column] = Units::percentToBp($data[$param] ?? null);
                    }
                    $this->run(fn (GateService $g) => $g->recordLabTest($e, $values), 'Lab test recorded');
                }),
            Action::make('accept')->label('Lab: accept')->color('success')->icon('heroicon-o-check')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('lab.decide') && LabTest::query()->where('gate_entry_id', $e->id)->exists())
                ->form([Textarea::make('note')->label('Note (required if above a reject limit)')->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->decideLab($e, true, $data['note'] ?? null), 'Lot accepted')),
            Action::make('reject')->label('Lab: reject')->color('danger')->icon('heroicon-o-x-mark')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('lab.decide') && LabTest::query()->where('gate_entry_id', $e->id)->exists())
                ->form([Textarea::make('note')->required()->maxLength(1000)])
                ->action(fn (GateEntry $e, array $data) => $this->run(fn (GateService $g) => $g->decideLab($e, false, $data['note']), 'Lot rejected')),
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
            Action::make('slip')->label('Issue weighment slip')->icon('heroicon-o-document-text')->color('primary')
                ->visible(fn (GateEntry $e) => $e->isOpen() && $can('weighment.capture') && ! $has($e, 'slip_issued') && $e->gross_kg !== null && $e->tare_kg !== null)
                ->requiresConfirmation()
                ->modalDescription('Freezes gross, tare, net and deductions, books the payable weight on the sauda and the net weight into the godown.')
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
