<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\EmployeeResource\Pages;

use App\Domain\Hr\EmployeeService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\EmployeeResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected static ?string $title = 'Add employee';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(EmployeeService::class)->create($data);
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages([self::fieldFor($e->getMessage()) => $e->getMessage()]);
        }
    }

    public static function fieldFor(string $message): string
    {
        return 'data.'.match (true) {
            str_contains($message, 'CNIC') => 'cnic',
            str_contains($message, 'NTN') => 'ntn',
            str_contains($message, 'birth') => 'date_of_birth',
            str_contains($message, 'Notice') => 'notice_period_days',
            str_contains($message, 'joining') => 'joining_date',
            default => 'full_name',
        };
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
