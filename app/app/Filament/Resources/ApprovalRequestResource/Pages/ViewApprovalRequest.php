<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApprovalRequestResource\Pages;

use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Filament\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewApprovalRequest extends ViewRecord
{
    protected static string $resource = ApprovalRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')->color('success')->icon('heroicon-o-check')
                ->visible(fn (ApprovalRequest $record) => Gate::allows('decide', $record))
                ->requiresConfirmation()
                ->form([Textarea::make('note')->maxLength(1000)])
                ->action(fn (ApprovalRequest $record, array $data) => $this->run(
                    fn (ApprovalService $s, LoginAccount $me) => $s->approve($record, $me, $data['note'] ?? null),
                )),
            Action::make('reject')->color('danger')->icon('heroicon-o-x-mark')
                ->visible(fn (ApprovalRequest $record) => Gate::allows('decide', $record))
                ->form([Textarea::make('note')->label('Reason')->required()->maxLength(1000)])
                ->action(fn (ApprovalRequest $record, array $data) => $this->run(
                    fn (ApprovalService $s, LoginAccount $me) => $s->reject($record, $me, $data['note']),
                )),
            Action::make('cancel')->label('Withdraw')->color('gray')
                ->visible(fn (ApprovalRequest $record) => Gate::allows('cancel', $record))
                ->requiresConfirmation()
                ->action(fn (ApprovalRequest $record) => $this->run(
                    fn (ApprovalService $s, LoginAccount $me) => $s->cancel($record, $me),
                )),
        ];
    }

    /** @param  callable(ApprovalService, LoginAccount): ApprovalRequest  $callback */
    private function run(callable $callback): void
    {
        /** @var LoginAccount $me */
        $me = Filament::auth()->user();

        try {
            $result = $callback(app(ApprovalService::class), $me);
        } catch (ApprovalDenied $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $notification = Notification::make()->title('Request '.$result->status);
        ($result->status === ApprovalRequest::STATUS_FAILED ? $notification->danger()->body($result->error) : $notification->success())->send();
        $this->refreshFormData([]);
        $this->record = $result;
    }
}
