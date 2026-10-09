<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\LeaveRequestResource\Pages;

use App\Domain\Hr\Leave\LeaveService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\LeaveRequestResource;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveType;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** HR enters a leave request on someone's behalf (it still needs an approver). */
class CreateLeaveRequest extends CreateRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected static ?string $title = 'Enter leave';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(LeaveService::class)->request(
                Employee::query()->findOrFail($data['employee_id']),
                LeaveType::query()->findOrFail($data['leave_type_id']),
                CarbonImmutable::parse($data['from_date'], 'Asia/Karachi'),
                CarbonImmutable::parse($data['to_date'], 'Asia/Karachi'),
                (string) $data['half_day'],
                (string) $data['reason'],
            );
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.from_date' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
