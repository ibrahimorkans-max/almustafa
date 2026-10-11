<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\AdvanceResource\Pages;

use App\Domain\Hr\Payroll\AdvanceService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\AdvanceResource;
use App\Filament\Support\Units;
use App\Models\Hr\Employee;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** HR requests an advance or loan; a different person approves it before it is paid out. */
class CreateAdvance extends CreateRecord
{
    protected static string $resource = AdvanceResource::class;

    protected static ?string $title = 'Request advance or loan';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(AdvanceService::class)->request(
                Employee::query()->findOrFail($data['employee_id']),
                (string) $data['kind'],
                Units::rupeesToPaise((string) $data['amount']),
                Units::rupeesToPaise((string) $data['installment']),
                (string) $data['start_month'],
                (string) $data['reason'],
            );
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.amount' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
