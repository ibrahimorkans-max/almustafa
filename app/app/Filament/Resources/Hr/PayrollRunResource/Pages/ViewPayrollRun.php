<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\PayrollRunResource\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\Engine\DocumentBatches;
use App\Domain\Documents\Engine\DocumentPresenter;
use App\Domain\Documents\Engine\DocumentRenderer;
use App\Domain\Documents\Engine\DocumentService;
use App\Domain\Documents\Engine\Types\PayrollRegisterDocument;
use App\Domain\Hr\Payroll\PayrollService;
use App\Domain\Hr\Payroll\SalaryPaymentService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\StatutoryReportsPage;
use App\Filament\Resources\Hr\DocumentBatchResource;
use App\Filament\Resources\Hr\PayrollRunResource;
use App\Filament\Support\DocumentActions;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\PayrollBankFile;
use App\Models\Hr\PayrollRun;
use App\Support\Export\XlsxWriter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @property PayrollRun $record
 */
class ViewPayrollRun extends ViewRecord
{
    protected static string $resource = PayrollRunResource::class;

    public function getTitle(): string
    {
        return "Payroll {$this->record->month}";
    }

    protected function getHeaderActions(): array
    {
        $is = fn (string ...$states) => fn () => in_array($this->record->status, $states, true);
        $can = fn (string $p) => PayrollRunResource::allows($p);

        return [
            Action::make('lock')->label('Lock attendance')->icon('heroicon-o-lock-closed')
                ->visible(fn () => $is(PayrollRun::DRAFT)() && $can('hr.payroll.run'))
                ->requiresConfirmation()->modalDescription('Attendance, leave and manual entries of this month are frozen from now on; unlock to correct them before approval.')
                ->action(fn () => $this->step(fn (PayrollService $s) => $s->lock($this->record), 'Attendance locked.')),
            Action::make('calculate')->label(fn () => $this->record->calculation_no > 0 ? 'Recalculate' : 'Calculate')->icon('heroicon-o-calculator')
                ->visible(fn () => $is(PayrollRun::LOCKED, PayrollRun::CALCULATED)() && $can('hr.payroll.run'))
                ->action(fn () => $this->step(fn (PayrollService $s) => $s->calculate($this->record), 'Calculated — check the warnings, then send for approval.')),
            Action::make('approve')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn () => $is(PayrollRun::CALCULATED)() && $can('hr.payroll.approve'))
                ->form([Textarea::make('note')->maxLength(500)])
                ->modalDescription('Approves the current calculation. Only someone other than the person who calculated it can approve.')
                ->action(fn (array $data) => $this->step(fn (PayrollService $s) => $s->approve($this->record, $data['note'] ?? null), 'Payroll approved.')),
            Action::make('finalize')->icon('heroicon-o-document-check')->color('success')
                ->visible(fn () => $is(PayrollRun::APPROVED)() && $can('hr.payroll.approve'))
                ->requiresConfirmation()->modalDescription('Posts the salary voucher, records advance recoveries and numbers the payslips. The payroll cannot change afterwards.')
                ->action(fn () => $this->step(fn (PayrollService $s) => $s->finalize($this->record), 'Finalized and posted to the books.')),
            Action::make('bankFile')->label('Bank transfer file')->icon('heroicon-o-building-library')
                ->visible(fn () => $is(PayrollRun::FINALIZED)() && $can('hr.payroll.approve'))
                ->form([
                    Select::make('bank')->label('Paid from')->required()
                        ->options(fn () => LedgerAccount::query()->where('kind', 'bank')->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                    Select::make('format')->required()->default('ibft')->options(collect(SalaryPaymentService::FORMATS)->map(fn (array $f) => $f[0])->all()),
                    DatePicker::make('date')->label('Transfer date')->required()->default(now('Asia/Karachi')),
                ])
                ->action(function (array $data): void {
                    try {
                        $r = app(SalaryPaymentService::class)->bankFile($this->record, LedgerAccount::query()->findOrFail($data['bank']), $data['format'],
                            CarbonImmutable::parse($data['date'], 'Asia/Karachi'));
                        $missing = $r['without_iban'] === [] ? null : 'No valid IBAN (pay by cash or cheque): '.implode(', ', $r['without_iban']);
                        $r['file'] === null
                            ? Notification::make()->title('Nobody left to pay by bank transfer.')->body($missing)->warning()->send()
                            : Notification::make()->title("{$r['file']->number}: {$r['file']->payment_count} transfers posted. Download it below.")->body($missing)->success()->persistent()->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('payslips')->label('All payslips')->icon('heroicon-o-printer')
                ->visible(fn () => $is(PayrollRun::CALCULATED, PayrollRun::APPROVED, PayrollRun::FINALIZED)() && DocumentActions::allowed())
                ->modalDescription(fn () => $this->record->status === PayrollRun::FINALIZED
                    ? 'Issues every payslip not issued yet and gives one PDF to print (half-A4: two slips per sheet).'
                    : 'Not finalized: a DRAFT of every payslip, for checking. Nothing is issued.')
                ->form(DocumentActions::form('payslip'))
                ->action(fn (array $data) => $this->allPayslips($data)),
            Action::make('register')->label('Payroll register')->icon('heroicon-o-table-cells')
                ->visible(fn () => $is(PayrollRun::CALCULATED, PayrollRun::APPROVED, PayrollRun::FINALIZED)() && DocumentActions::allowed())
                ->form(DocumentActions::form('payroll_register'))
                ->action(fn (array $data) => DocumentActions::run('payroll_register', $this->record->id, $data)),
            ActionGroup::make([
                Action::make('queuePayslips')->label('Payslips on the queue (large payrolls)')->icon('heroicon-o-queue-list')
                    ->visible(fn () => $is(PayrollRun::FINALIZED)() && DocumentActions::allowed())
                    ->action(function () {
                        try {
                            app(DocumentBatches::class)->start('payslip', $this->record->currentItems()->whereHas('payslip')->orderBy('employee->code')->pluck('id')->all(),
                                "Payslips {$this->record->month}", ['paper' => 'half_a4']);

                            return $this->redirect(DocumentBatchResource::getUrl('index'));
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return null;
                        }
                    }),
                Action::make('registerXlsx')->label('Payroll register (Excel)')->icon('heroicon-o-table-cells')
                    ->visible(fn () => $is(PayrollRun::CALCULATED, PayrollRun::APPROVED, PayrollRun::FINALIZED)() && DocumentActions::allowed())
                    ->action(fn () => $this->registerXlsx()),
                Action::make('download')->label('Download bank file')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => $is(PayrollRun::FINALIZED)() && $can('bank.view'))
                    ->form([Select::make('file')->required()->options(fn () => PayrollBankFile::query()->where('payroll_run_id', $this->record->id)
                        ->orderByDesc('created_at')->pluck('number', 'id'))])
                    ->action(function (array $data) {
                        $file = PayrollBankFile::query()->where('payroll_run_id', $this->record->id)->findOrFail($data['file']);
                        try {
                            $csv = app(SalaryPaymentService::class)->csv($file);
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return null;
                        }

                        return response()->streamDownload(fn () => print $csv, str_replace('/', '-', $file->number).'.csv', ['Content-Type' => 'text/csv']);
                    }),
                Action::make('reports')->label('Statutory reports')->icon('heroicon-o-document-chart-bar')
                    ->visible(fn () => $is(PayrollRun::FINALIZED)() && StatutoryReportsPage::canAccess())
                    ->url(fn () => StatutoryReportsPage::getUrl(['month' => $this->record->month])),
                Action::make('unlock')->label('Unlock attendance')->icon('heroicon-o-lock-open')->color('danger')
                    ->visible(fn () => $is(PayrollRun::LOCKED, PayrollRun::CALCULATED)() && $can('hr.payroll.run'))
                    ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                    ->action(fn (array $data) => $this->step(fn (PayrollService $s) => $s->unlock($this->record, $data['reason']), 'Unlocked — correct attendance, then lock and calculate again.')),
            ]),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function allPayslips(array $data): ?StreamedResponse
    {
        $ids = $this->record->currentItems()->orderBy('employee->code')->pluck('id')->all();
        $svc = app(DocumentService::class);
        try {
            if ($this->record->status !== PayrollRun::FINALIZED) {
                $pdfs = array_map(fn (string $id) => $svc->preview('payslip', $id, ['format' => 'pdf', 'paper' => 'a4'] + array_filter($data))['bytes'], $ids);

                return DocumentActions::stream(['bytes' => app(DocumentRenderer::class)->merge($pdfs), 'mime' => 'application/pdf', 'name' => "payslips-draft-{$this->record->month}.pdf"]);
            }
            $r = $svc->issueMany('payslip', $ids, array_filter($data));
            if ($r['failed'] !== []) {
                Notification::make()->title(count($r['failed']).' payslips could not be issued')->body(implode("\n", array_unique($r['failed'])))->warning()->persistent()->send();
            }

            return DocumentActions::stream(['bytes' => $svc->combined($r['documents'], array_filter($data)), 'mime' => 'application/pdf', 'name' => "payslips-{$this->record->month}.pdf"]);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }
    }

    private function registerXlsx(): StreamedResponse
    {
        $payload = app(PayrollRegisterDocument::class)->build($this->record, []);
        $p = new DocumentPresenter('en');
        $t = $payload['tables'][0];
        $cell = fn ($c) => is_array($c) && array_key_exists('m', $c) ? round($c['m'] / 100, 2) : (is_array($c) && array_key_exists('n', $c) ? (float) $c['n'] : (is_array($c) ? $p->plain($c) : $c));
        $rows = array_map(fn (array $r) => array_map($cell, $r), [...$t['rows'], $t['total']]);
        app(AuditLogger::class)->record('hr.payroll.report_exported', $this->record, meta: ['report' => 'payroll_register', 'format' => 'xlsx']);
        $bytes = XlsxWriter::build('Payroll '.$this->record->month, array_map(fn (string $k) => $p->plain(['k' => $k]), $t['cols']), $rows);

        return DocumentActions::stream(['bytes' => $bytes, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'name' => "payroll-register-{$this->record->month}.xlsx"]);
    }

    private function step(callable $do, string $ok): void
    {
        try {
            $this->record = $do(app(PayrollService::class));
            Notification::make()->title($ok)->success()->send();
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
        $this->record->refresh();
    }
}
