<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\EmployeeResource\Pages;

use App\Domain\Approvals\Actions\EmployeeBankAccountChange;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Hr\EmployeeDocuments;
use App\Domain\Hr\EmployeeService;
use App\Domain\Hr\SalaryStructureService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\EmployeeResource;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\Units;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeDocument;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\SalaryStructure;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    /** @var array<string, string|null> values revealed on this page (each reveal audited) */
    public array $revealed = [];

    public function reveal(string $field): void
    {
        /** @var Employee $employee */
        $employee = $this->record;
        $this->attempt(function () use ($employee, $field): void {
            $this->revealed[$field] = app(EmployeeService::class)->reveal($employee, $field) ?? '—';
        });
    }

    private function can(string $permission): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission($permission);
    }

    protected function getHeaderActions(): array
    {
        $open = fn (Employee $r) => $r->status !== Employee::EXITED;

        return [
            EditAction::make()->visible(fn (Employee $r) => $open($r) && $this->can('hr.employee.edit')),
            Action::make('salary')->label('New salary version')->icon('heroicon-o-banknotes')
                ->visible(fn (Employee $r) => $open($r) && $this->can('hr.salary.edit'))
                ->modalDescription('The current version stays in the history; this one applies from its effective date.')
                ->form(fn () => [
                    Grid::make(2)->schema([
                        DatePicker::make('effective_from')->required()->default(now()),
                        Select::make('pay_basis')->options(SalaryStructure::BASES)->required()->live()
                            ->default(fn (Employee $record) => match ($record->employment_type) {
                                'daily_wage' => 'daily', 'piece_rate' => 'piece_rate', default => 'monthly',
                            }),
                    ]),
                    Grid::make(3)->schema(SalaryComponent::query()->where('status', 'active')->orderBy('sort')->get()
                        ->flatMap(fn (SalaryComponent $c) => [
                            TextInput::make("amount.{$c->code}")->label("{$c->name} (₹)")->regex(Units::DECIMAL_2)
                                ->helperText(fn (Get $get) => $c->code === 'BASIC' ? match ($get('pay_basis')) {
                                    'daily' => 'Daily rate', 'piece_rate' => 'Rate per unit', default => 'Per month',
                                } : null),
                            TextInput::make("unit.{$c->code}")->label('Unit (bag, maund…)')->maxLength(20)
                                ->visible(fn (Get $get) => $get('pay_basis') === 'piece_rate' && $c->kind === SalaryComponent::EARNING),
                        ])->all()),
                    Textarea::make('reason')->required()->minLength(3)->maxLength(500)->placeholder('Joining / annual increment / correction'),
                ])
                ->action(fn (Employee $record, array $data) => $this->attempt(function () use ($record, $data): void {
                    $amounts = [];
                    foreach ((array) ($data['amount'] ?? []) as $code => $rupees) {
                        if (filled($rupees)) {
                            $amounts[(string) $code] = Units::rupeesToPaise((string) $rupees);
                        }
                    }
                    app(SalaryStructureService::class)->create($record, CarbonImmutable::parse($data['effective_from']), $data['pay_basis'],
                        $amounts, $data['reason'], array_filter($data['unit'] ?? []));
                    Notification::make()->title('Salary version saved.')->success()->send();
                })),
            Action::make('bank')->label('Change salary account')->icon('heroicon-o-building-library')
                ->visible(fn (Employee $r) => $open($r) && $this->can('hr.employee.edit'))
                ->modalDescription('Goes to approval: someone who approves payroll must confirm it before salary is paid there.')
                ->form([
                    TextInput::make('account_holder')->required()->maxLength(120)->default(fn (Employee $record) => $record->name()),
                    TextInput::make('account_number')->label('IBAN')->required()->maxLength(34)->placeholder('PK36 SCBL 0000 0011 2345 6702')->autocomplete('off'),
                    TextInput::make('bank_name')->maxLength(120)->placeholder('e.g. HBL, Meezan Bank'),
                ])
                ->action(fn (Employee $record, array $data) => $this->attempt(function () use ($record, $data): void {
                    app(ApprovalService::class)->submit(EmployeeBankAccountChange::KEY, ['employee_id' => $record->id] + $data,
                        "Salary account for {$record->code} {$record->name()}", 'Requested from the employee record');
                    Notification::make()->title('Sent for approval.')->body('The new account is used only after it is approved.')->success()->send();
                })),
            Action::make('certificate')->label('Certificate')->icon('heroicon-o-academic-cap')->color('gray')
                ->visible(fn (Employee $r) => $r->status !== Employee::EXITED && DocumentActions::allowed())
                ->form([
                    Select::make('kind')->label('Certificate')->required()->default('employment_letter')
                        ->options(['employment_letter' => 'Employment certificate', 'salary_certificate' => 'Salary certificate (with gross salary)']),
                    ...DocumentActions::form('salary_certificate'),
                ])
                ->action(fn (Employee $record, array $data) => DocumentActions::run((string) $data['kind'], $record->id, $data)),
            Action::make('document')->label('Upload document')->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (Employee $r) => $open($r) && $this->can('hr.employee.edit'))
                ->form([
                    Select::make('kind')->options(EmployeeDocument::KINDS)->required(),
                    TextInput::make('title')->maxLength(160),
                    FileUpload::make('file')->required()->storeFiles(false)->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(5120),
                    DatePicker::make('valid_until')->label('Valid until (if it expires)'),
                ])
                ->action(fn (Employee $record, array $data) => $this->attempt(function () use ($record, $data): void {
                    $file = $data['file'];
                    $bytes = $file instanceof TemporaryUploadedFile ? (string) $file->get() : (string) file_get_contents((string) $file);
                    app(EmployeeDocuments::class)->upload($record, $data['kind'], (string) ($data['title'] ?? ''), $bytes,
                        filled($data['valid_until'] ?? null) ? CarbonImmutable::parse($data['valid_until']) : null);
                    Notification::make()->title('Document stored.')->success()->send();
                })),
            Action::make('notice')->label('Give notice')->icon('heroicon-o-arrow-right-start-on-rectangle')->color('warning')
                ->visible(fn (Employee $r) => $r->status === Employee::ACTIVE && $this->can('hr.employee.edit'))
                ->form([
                    DatePicker::make('given_on')->label('Notice given on')->required()->default(now()),
                    DatePicker::make('last_working_day')->helperText('Empty: notice date + notice period'),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(fn (Employee $record, array $data) => $this->attempt(function () use ($record, $data): void {
                    app(EmployeeService::class)->giveNotice($record, CarbonImmutable::parse($data['given_on']), $data['reason'],
                        filled($data['last_working_day'] ?? null) ? CarbonImmutable::parse($data['last_working_day']) : null);
                    Notification::make()->title('Notice recorded. Clearance checklist opened.')->success()->send();
                })),
            Action::make('withdrawNotice')->label('Withdraw notice')->color('gray')
                ->visible(fn (Employee $r) => $r->status === Employee::ON_NOTICE && $this->can('hr.employee.edit'))
                ->form([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (Employee $record, array $data) => $this->attempt(fn () => app(EmployeeService::class)->withdrawNotice($record, $data['reason']))),
            Action::make('exit')->label('Mark exited')->color('danger')->icon('heroicon-o-user-minus')
                ->visible(fn (Employee $r) => $r->status === Employee::ON_NOTICE && $this->can('hr.employee.edit'))
                ->modalDescription('Needs a complete clearance checklist. The person\'s ERP logins in this entity are revoked at once; the record is kept.')
                ->requiresConfirmation()
                ->form([DatePicker::make('exited_on')->required()->default(fn (Employee $record) => $record->last_working_day)])
                ->action(fn (Employee $record, array $data) => $this->attempt(function () use ($record, $data): void {
                    app(EmployeeService::class)->markExited($record, CarbonImmutable::parse($data['exited_on']));
                    Notification::make()->title('Employee exited. Logins revoked.')->success()->send();
                })),
        ];
    }

    private function attempt(callable $do): void
    {
        try {
            $do();
        } catch (BusinessRuleViolation|ApprovalDenied $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first() ?? 'Check the entries.')->danger()->send();

            return;
        }
        $this->record->refresh();
    }
}
