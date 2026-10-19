<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyWages\DailyWageSheetResource\Pages;

use App\Domain\DailyWages\DailyWages;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\DailyWages\DailyWageSheetResource;
use App\Filament\Support\OffersPrintAfterCreate;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateDailyWageSheet extends CreateRecord
{
    use OffersPrintAfterCreate;

    protected string $printType = 'daily_wage';

    protected static string $resource = DailyWageSheetResource::class;

    protected static ?string $title = 'Enter daily wages';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $service = app(DailyWages::class);
        try {
            return DB::transaction(function () use ($service, $data): Model {
                $sheet = $service->record([
                    'work_date' => (string) $data['work_date'],
                    'group_id' => ($data['who'] ?? 'group') === 'single' ? null : ($data['group_id'] ?? null),
                    'worker_name' => ($data['who'] ?? 'group') === 'single' ? ($data['worker_name'] ?? null) : null,
                    'category_id' => (string) $data['category_id'],
                    'workers' => (int) $data['workers'],
                    'quantity' => (string) $data['quantity'],
                    'rate_paise' => Units::rupeesToPaise((string) $data['rate']),
                    'extra_paise' => filled($data['extra'] ?? null) ? Units::rupeesToPaise((string) $data['extra']) : 0,
                    'deduction_paise' => filled($data['deduction'] ?? null) ? Units::rupeesToPaise((string) $data['deduction']) : 0,
                    'worker_names' => $data['worker_names'] ?? null,
                    'description' => $data['description'] ?? null,
                ]);
                $pay = (string) ($data['pay'] ?? 'draft');
                if ($pay !== 'draft' && DailyWageSheetResource::canPay()) {
                    $sheet = $service->post($sheet, $pay === 'later' ? null : LedgerAccount::query()->findOrFail($data['paid_from']));
                }

                return $sheet;
            });
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.workers' => $e->getMessage()]);
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Daily wages saved';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
