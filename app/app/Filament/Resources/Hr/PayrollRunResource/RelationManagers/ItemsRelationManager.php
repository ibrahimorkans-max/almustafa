<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\PayrollRunResource\RelationManagers;

use App\Domain\Hr\Payroll\SalaryPaymentService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\PayrollRunResource;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollPayment;
use App\Models\Hr\PayrollPaymentAck;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunAttendance;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** One line per employee of the current calculation, with its pay lines, warnings and payment status. */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Employees';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var PayrollRun $run */
        $run = $this->getOwnerRecord();
        $rs = fn (?int $state) => Units::paiseToRupees($state);
        $pay = fn () => app(SalaryPaymentService::class);
        $finalized = fn () => $run->status === PayrollRun::FINALIZED;
        $due = fn (PayrollItem $r): bool => $finalized() && $pay()->due($r) > 0 && PayrollRunResource::allows('hr.payroll.approve');
        $try = fn (callable $do, string $ok) => function (PayrollItem $record, array $data) use ($do, $ok): void {
            try {
                $do($record, $data);
                Notification::make()->title($ok)->success()->send();
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };
        $date = fn () => DatePicker::make('date')->required()->default(now('Asia/Karachi'))->maxDate(now('Asia/Karachi'));

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('calculation_no', $run->calculation_no)->with('payslip')->withSum('payments', 'amount_paise'))
            ->defaultSort('employee->code')
            ->emptyStateHeading($run->calculation_no > 0 ? 'Nobody in this calculation' : 'Not calculated yet')
            ->columns([
                TextColumn::make('employee.code')->label('Code')->searchable(query: fn (Builder $query, string $search) => $query->where('employee->code', 'like', "%{$search}%")),
                TextColumn::make('employee.name')->label('Name'),
                TextColumn::make('employee.department')->label('Department')->toggleable(),
                TextColumn::make('result.pay_basis')->label('Basis')->toggleable(),
                TextColumn::make('result.info.paid_days')->label('Paid days')->alignEnd(),
                TextColumn::make('gross_paise')->label('Gross')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('deductions_paise')->label('Deductions')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('net_paise')->label('Net')->alignEnd()->weight('bold')->formatStateUsing($rs),
                TextColumn::make('warning_count')->label('Warnings')->alignEnd()->color(fn (int $state) => $state > 0 ? 'warning' : null),
                TextColumn::make('paid')->label('Paid')->visible($finalized)->badge()
                    ->state(fn (PayrollItem $r) => match (true) {
                        $r->net_paise <= 0 => 'nothing due',
                        (int) $r->getAttribute('payments_sum_amount_paise') >= $r->net_paise => 'paid',
                        (int) $r->getAttribute('payments_sum_amount_paise') > 0 => 'part paid',
                        default => 'unpaid',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success', 'part paid' => 'warning', 'unpaid' => 'danger', default => 'gray',
                    }),
                TextColumn::make('payslip.number')->label('Payslip')->placeholder('—')->visible($finalized),
            ])
            ->filters([Filter::make('warnings')->label('With warnings')->query(fn (Builder $query) => $query->where('warning_count', '>', 0))])
            ->actions([
                Action::make('lines')->label('Details')->icon('heroicon-o-list-bullet')->color('gray')
                    ->modalHeading(fn (PayrollItem $r) => "{$r->employee['code']} {$r->employee['name']}")
                    ->modalContent(fn (PayrollItem $record) => view('filament.payroll.item-lines', ['item' => $record]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('payslip')->icon('heroicon-o-document-text')->color('gray')->visible(fn () => DocumentActions::allowed())
                    ->form(DocumentActions::form('payslip'))
                    ->action(fn (PayrollItem $record, array $data) => DocumentActions::run('payslip', $record->id, $data)),
                ActionGroup::make([
                    Action::make('attendanceSlip')->label('Attendance summary')->icon('heroicon-o-calendar-days')->visible(fn () => DocumentActions::allowed())
                        ->form(DocumentActions::form('attendance_summary'))
                        ->action(fn (PayrollItem $record, array $data) => ($snap = PayrollRunAttendance::query()->where('payroll_run_id', $record->payroll_run_id)
                            ->where('employee_id', $record->employee_id)->orderByDesc('lock_no')->first()) !== null
                            ? DocumentActions::run('attendance_summary', $snap->id, $data) : null),
                    Action::make('receipt')->label('Payment receipt')->icon('heroicon-o-receipt-percent')
                        ->visible(fn (PayrollItem $r) => $finalized() && DocumentActions::allowed() && PayrollPayment::query()->where('payroll_item_id', $r->id)->whereIn('mode', ['cash', 'cheque'])->exists())
                        ->form(DocumentActions::form('cash_receipt'))
                        ->action(fn (PayrollItem $record, array $data) => DocumentActions::run('cash_receipt', (string) PayrollPayment::query()->where('payroll_item_id', $record->id)
                            ->whereIn('mode', ['cash', 'cheque'])->orderByDesc('created_at')->value('id'), $data)),
                    Action::make('payCash')->label('Pay cash')->icon('heroicon-o-banknotes')->visible($due)
                        ->modalDescription(fn (PayrollItem $r) => 'Due: Rs '.Units::paiseToRupees($pay()->due($r)).'. Above the cash limit, salary must go through the bank (s. 21(m)).')
                        ->form([
                            Select::make('cash')->label('Paid from')->required()
                                ->options(fn () => LedgerAccount::query()->where('kind', 'cash')->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                            $date(),
                        ])
                        ->action($try(fn (PayrollItem $r, array $d) => $pay()->payCash($r, LedgerAccount::query()->findOrFail($d['cash']), CarbonImmutable::parse($d['date'], 'Asia/Karachi')),
                            'Cash payment posted — get the signature or thumb.')),
                    Action::make('payCheque')->label('Pay by cheque')->icon('heroicon-o-document-text')->visible($due)
                        ->form([
                            Select::make('bank')->label('Bank account')->required()
                                ->options(fn () => LedgerAccount::query()->where('kind', 'bank')->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                            TextInput::make('cheque')->label('Cheque number')->required()->maxLength(30),
                            $date(),
                        ])
                        ->action($try(fn (PayrollItem $r, array $d) => $pay()->payCheque($r, LedgerAccount::query()->findOrFail($d['bank']), $d['cheque'],
                            CarbonImmutable::parse($d['date'], 'Asia/Karachi')), 'Cheque payment posted.')),
                    Action::make('acknowledge')->label('Receipt signature / thumb')->icon('heroicon-o-finger-print')
                        ->visible(fn (PayrollItem $r) => $finalized() && PayrollRunResource::allows('hr.payroll.run') && $this->unacknowledged($r) !== null)
                        ->form([
                            Radio::make('method')->options(['signature' => 'Signature', 'thumb' => 'Thumb impression'])->default('thumb')->required()->inline(),
                            FileUpload::make('photo')->required()->storeFiles(false)->image()->acceptedFileTypes(['image/jpeg', 'image/png'])->maxSize(5120),
                        ])
                        ->action($try(function (PayrollItem $r, array $d) use ($pay): void {
                            $file = $d['photo'];
                            $bytes = $file instanceof TemporaryUploadedFile ? (string) $file->get() : (string) file_get_contents((string) $file);
                            $pay()->acknowledge($this->unacknowledged($r) ?? throw new BusinessRuleViolation('Nothing to acknowledge.'), $d['method'], $bytes);
                        }, 'Acknowledgement stored.')),
                ]),
            ]);
    }

    /** The cash payment of this item still waiting for the employee's signature or thumb. */
    private function unacknowledged(PayrollItem $item): ?PayrollPayment
    {
        return PayrollPayment::query()->where('payroll_item_id', $item->id)->where('mode', 'cash')
            ->whereNotIn('id', PayrollPaymentAck::query()->select('payroll_payment_id'))->orderBy('created_at')->first();
    }
}
