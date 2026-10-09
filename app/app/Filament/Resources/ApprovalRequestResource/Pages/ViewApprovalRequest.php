<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApprovalRequestResource\Pages;

use App\Domain\Approvals\ApprovalService;
use App\Filament\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewApprovalRequest extends ViewRecord
{
    protected static string $resource = ApprovalRequestResource::class;

    protected function getHeaderActions(): array
    {
        $after = function (ApprovalRequest $result): void {
            $this->record = $result;
            $this->refreshFormData([]);
        };

        return [
            ApprovalRequestResource::approveAction(Action::make('approve'), $after),
            ApprovalRequestResource::rejectAction(Action::make('reject'), $after),
            Action::make('cancel')->label('Withdraw')->color('gray')
                ->visible(fn (ApprovalRequest $record) => Gate::allows('cancel', $record))
                ->requiresConfirmation()
                ->action(fn (ApprovalRequest $record) => ApprovalRequestResource::runDecision(
                    fn (ApprovalService $s, LoginAccount $me) => $s->cancel($record, $me), $after,
                )),
        ];
    }

    public function getSubheading(): ?string
    {
        /** @var ApprovalRequest $record */
        $record = $this->record;
        $me = Filament::auth()->user();
        if (! $me instanceof LoginAccount || $record->status !== ApprovalRequest::STATUS_PENDING) {
            return null;
        }
        $blocker = app(ApprovalService::class)->decisionBlocker($me, $record);

        return $blocker === null ? 'You can approve or reject this request.' : $blocker;
    }
}
