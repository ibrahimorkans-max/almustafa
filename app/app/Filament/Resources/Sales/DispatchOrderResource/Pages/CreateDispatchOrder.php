<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\DispatchOrderResource\Pages;

use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Sales\DispatchService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Sales\DispatchOrderResource;
use App\Models\Masters\Godown;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateDispatchOrder extends CreateRecord
{
    protected static string $resource = DispatchOrderResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $order = app(DispatchService::class)->create(
                Sauda::query()->findOrFail($data['sauda_id']),
                Godown::query()->findOrFail($data['godown_id']),
                (string) $data['quantity_kg'],
                filled($data['bag_count'] ?? null) ? (int) $data['bag_count'] : null,
                filled($data['bag_weight_kg'] ?? null) ? (string) $data['bag_weight_kg'] : null,
                $data['note'] ?? null,
            );
        } catch (BusinessRuleViolation|ApprovalDenied $e) {
            throw ValidationException::withMessages(['data.quantity_kg' => $e->getMessage()]);
        }
        if ($order->status === DispatchOrder::PENDING_CREDIT) {
            Notification::make()->warning()->title('Over the buyer\'s credit limit')
                ->body('The order waits for a credit override by a second person.')->persistent()->send();
        }

        return $order;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
