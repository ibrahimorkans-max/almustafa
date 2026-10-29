<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Filament\Resources\Hr\EmployeeResource\Pages;
use App\Filament\Resources\Hr\EmployeeResource\RelationManagers;
use App\Models\Hr\Department;
use App\Models\Hr\Designation;
use App\Models\Hr\Employee;
use App\Models\Hr\Shift;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section as FormSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'code';

    public static function canSeeSensitive(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('hr.employee.sensitive.view');
    }

    public static function form(Form $form): Form
    {
        $sensitive = static::canSeeSensitive();

        return $form->columns(2)->schema([
            FormSection::make('Person')->columns(3)->schema([
                TextInput::make('full_name')->label('Full name')->required()->maxLength(160),
                TextInput::make('phone')->tel()->maxLength(15),
                TextInput::make('email')->email()->maxLength(160),
            ]),
            FormSection::make('Employment')->columns(3)->schema([
                Select::make('department_id')->label('Department')
                    ->options(fn () => Department::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                Select::make('designation_id')->label('Designation')
                    ->options(fn () => Designation::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                Select::make('employment_type')->options(Employee::TYPES)->required(),
                Select::make('shift_id')->label('Shift')
                    ->options(fn () => Shift::query()->where('status', 'active')->orderBy('code')->get()
                        ->mapWithKeys(fn (Shift $s) => [$s->id => "{$s->name} ({$s->start_time}–{$s->end_time})"])->all()),
                DatePicker::make('joining_date')->required()->disabledOn('edit')->maxDate(now()->addMonths(3)),
                TextInput::make('notice_period_days')->label('Notice period (days)')->numeric()->minValue(0)->maxValue(180)->default(30),
            ]),
            FormSection::make('Personal details')->columns(2)
                ->description('Encrypted. CNIC and NTN are shown masked; leave a field empty on edit to keep the stored value.')
                ->schema([
                    TextInput::make('cnic')->label('CNIC')->maxLength(15)->autocomplete('off')
                        ->placeholder(fn (?Employee $record) => $record?->maskedCnic()),
                    TextInput::make('ntn')->label('NTN (if any)')->maxLength(15)->autocomplete('off')
                        ->placeholder(fn (?Employee $record) => $record?->maskedNtn()),
                    DatePicker::make('date_of_birth')->maxDate(now()->subYears(15))
                        ->visible(fn (?Employee $record) => $record === null || $sensitive),
                    Textarea::make('address')->maxLength(500)->visible(fn (?Employee $record) => $record === null || $sensitive),
                    TextInput::make('emergency_contact_name')->maxLength(120),
                    TextInput::make('emergency_contact_phone')->tel()->maxLength(20),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('person.full_name')->label('Name')->searchable(),
                TextColumn::make('department.name')->label('Department')->placeholder('—'),
                TextColumn::make('designation.name')->label('Designation')->placeholder('—'),
                TextColumn::make('contractor.name')->label('Contractor')->placeholder('Mill staff')->toggleable(),
                TextColumn::make('employment_type')->label('Type')->formatStateUsing(fn (string $state) => Employee::TYPES[$state] ?? $state),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'active' => 'success', 'on_notice' => 'warning', default => 'gray',
                }),
                TextColumn::make('joining_date')->date('d M Y')->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'on_notice' => 'On notice', 'exited' => 'Exited']),
                SelectFilter::make('department_id')->label('Department')->relationship('department', 'name'),
                SelectFilter::make('employment_type')->label('Type')->options(Employee::TYPES),
                SelectFilter::make('contractor_party_id')->label('Contractor')->relationship('contractor', 'name'),
            ])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $reveal = fn (string $field) => InfolistAction::make('reveal_'.$field)->label('Reveal')->icon('heroicon-o-eye')
            ->visible(fn () => static::canSeeSensitive())
            ->requiresConfirmation()->modalDescription('The full value is shown once and the reveal is recorded in the audit log.')
            ->action(fn (Employee $record, $livewire) => $livewire->reveal($field));

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('code'),
                TextEntry::make('person.full_name')->label('Name'),
                TextEntry::make('status')->badge(),
                TextEntry::make('employment_type')->label('Type')->formatStateUsing(fn (string $state) => Employee::TYPES[$state] ?? $state),
                TextEntry::make('department.name')->label('Department')->placeholder('—'),
                TextEntry::make('designation.name')->label('Designation')->placeholder('—'),
                TextEntry::make('shift.name')->label('Shift')->placeholder('default 09:00–18:00'),
                TextEntry::make('joining_date')->date('d M Y'),
                TextEntry::make('notice_period_days')->label('Notice period')->suffix(' days'),
                TextEntry::make('person.phone')->label('Phone')->placeholder('—'),
                TextEntry::make('person.email')->label('E-mail')->placeholder('—'),
                TextEntry::make('emergency')->label('Emergency contact')->placeholder('—')
                    ->state(fn (Employee $r) => trim(($r->emergency_contact_name ?? '').' '.($r->emergency_contact_phone ?? '')) ?: null),
                TextEntry::make('logins')->label('ERP login')->placeholder('none')
                    ->state(fn (Employee $r) => $r->loginAccounts()->map(fn (LoginAccount $a) => "{$a->username} ({$a->role?->name}, {$a->status})")->implode(', ') ?: null),
            ]),
            Section::make('Identity and bank')->columns(3)
                ->description('Masked. Full values need hr.employee.sensitive.view; each reveal is audited.')
                ->schema([
                    TextEntry::make('cnic_view')->label('CNIC')->fontFamily('mono')
                        ->state(fn (Employee $r, $livewire) => $livewire->revealed['cnic'] ?? $r->maskedCnic())->hintAction($reveal('cnic')),
                    TextEntry::make('ntn_view')->label('NTN')->fontFamily('mono')
                        ->state(fn (Employee $r, $livewire) => $livewire->revealed['ntn'] ?? $r->maskedNtn())->hintAction($reveal('ntn')),
                    TextEntry::make('dob_view')->label('Date of birth')
                        ->state(fn (Employee $r, $livewire) => $livewire->revealed['date_of_birth'] ?? ($r->getRawOriginal('date_of_birth') ? 'hidden' : '—'))
                        ->hintAction($reveal('date_of_birth')),
                    TextEntry::make('bank_view')->label('Salary account')->fontFamily('mono')
                        ->state(fn (Employee $r, $livewire) => isset($livewire->revealed['bank_account_number'])
                            ? $livewire->revealed['bank_account_number'].' · '.$r->bank_code : $r->maskedBankAccount())
                        ->hintAction($reveal('bank_account_number')),
                    TextEntry::make('bank_name')->placeholder('—'),
                    TextEntry::make('bank_account_holder')->label('Account holder')->placeholder('—'),
                    TextEntry::make('address_view')->label('Address')->columnSpanFull()
                        ->state(fn (Employee $r, $livewire) => $livewire->revealed['address'] ?? ($r->getRawOriginal('address') ? 'hidden' : '—'))
                        ->hintAction($reveal('address')),
                ]),
            Section::make('Exit')->columns(4)->visible(fn (Employee $r) => $r->notice_given_on !== null || $r->exited_on !== null)->schema([
                TextEntry::make('notice_given_on')->date('d M Y'),
                TextEntry::make('last_working_day')->date('d M Y'),
                TextEntry::make('exited_on')->date('d M Y')->placeholder('—'),
                TextEntry::make('exit_reason')->placeholder('—'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\SalaryStructuresRelationManager::class,
            RelationManagers\ClearanceItemsRelationManager::class,
            RelationManagers\LeaveLedgerRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'view' => Pages\ViewEmployee::route('/{record}'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
