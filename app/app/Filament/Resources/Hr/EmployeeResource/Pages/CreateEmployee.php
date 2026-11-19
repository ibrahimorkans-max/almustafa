<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\EmployeeResource\Pages;

use App\Domain\Hr\EmployeeService;
use App\Domain\Hr\SalaryStructureService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Concerns\TranslatesLabels;
use App\Filament\Resources\Hr\EmployeeResource;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEmployee extends CreateRecord
{
    use TranslatesLabels;

    protected static string $resource = EmployeeResource::class;

    protected static ?string $title = 'Add employee';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $basis = (string) ($data['salary_basis'] ?? 'monthly');
        $amount = trim((string) ($data['salary_amount'] ?? ''));
        unset($data['salary_basis'], $data['salary_amount']);
        try {
            // The employee and the salary are saved together, or not at all.
            return DB::transaction(function () use ($data, $basis, $amount): Model {
                $employee = app(EmployeeService::class)->create($data);
                if ($amount !== '' && Money::rupees($amount)->paise > 0) {
                    app(SalaryStructureService::class)->create($employee, CarbonImmutable::parse($employee->joining_date)->startOfDay(),
                        $basis === 'daily' ? 'daily' : 'monthly', ['BASIC' => Money::rupees($amount)->paise], 'Salary on joining');
                }

                return $employee;
            });
        } catch (BusinessRuleViolation $e) {
            $field = str_contains($e->getMessage(), 'Basic') || str_contains($e->getMessage(), 'salary') || str_contains($e->getMessage(), 'pay head')
                ? 'data.salary_amount' : self::fieldFor($e->getMessage());

            throw ValidationException::withMessages([$field => __($e->getMessage())]);
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
