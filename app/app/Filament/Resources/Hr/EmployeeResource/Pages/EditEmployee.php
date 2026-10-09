<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\EmployeeResource\Pages;

use App\Domain\Hr\EmployeeService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\EmployeeResource;
use App\Models\Hr\Employee;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * Identifiers are never put into the form in clear: Aadhaar / PAN stay empty (masked placeholder)
     * and are changed only when something new is typed. Date of birth and address are filled only for
     * people allowed to see them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Employee $e */
        $e = $this->record;
        $sensitive = EmployeeResource::canSeeSensitive();

        return [
            'full_name' => $e->person->full_name, 'phone' => $e->person->phone, 'email' => $e->person->email,
            'department_id' => $e->department_id, 'designation_id' => $e->designation_id, 'shift_id' => $e->shift_id,
            'employment_type' => $e->employment_type, 'joining_date' => $e->joining_date->toDateString(),
            'notice_period_days' => $e->notice_period_days,
            'emergency_contact_name' => $e->emergency_contact_name, 'emergency_contact_phone' => $e->emergency_contact_phone,
            'aadhaar' => null, 'pan' => null,
            'date_of_birth' => $sensitive ? $e->date_of_birth : null,
            'address' => $sensitive ? $e->address : null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Employee $record */
        $data = array_filter($data, fn ($v, $k) => ! (in_array($k, ['aadhaar', 'pan'], true) && blank($v)), ARRAY_FILTER_USE_BOTH);
        unset($data['joining_date']);
        if (! EmployeeResource::canSeeSensitive()) {
            unset($data['date_of_birth'], $data['address']);
        }
        try {
            return app(EmployeeService::class)->update($record, $data);
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages([CreateEmployee::fieldFor($e->getMessage()) => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
