<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\AttendanceLogResource\Pages;

use App\Domain\Hr\Attendance\AttendanceReviewService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\AttendanceLogResource;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\AttendanceReview;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAttendanceLog extends ViewRecord
{
    protected static string $resource = AttendanceLogResource::class;

    protected function getHeaderActions(): array
    {
        $visible = function (AttendanceLog $record): bool {
            $user = Filament::auth()->user();

            return $record->status === AttendanceLog::FLAGGED && $user instanceof LoginAccount && $user->hasPermission('hr.attendance.review');
        };
        $decide = fn (string $decision) => function (AttendanceLog $record, array $data) use ($decision): void {
            try {
                app(AttendanceReviewService::class)->decide($record, $decision, (string) $data['reason']);
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();

                return;
            }
            Notification::make()->title($decision === AttendanceReview::ACCEPT ? 'Punch accepted.' : 'Punch rejected.')->success()->send();
            $this->record->refresh();
        };

        return [
            Action::make('accept')->label('Accept')->color('success')->icon('heroicon-o-check')->visible($visible)
                ->modalDescription('The punch counts as attendance. Say what you checked (photo, place, the employee\'s explanation).')
                ->form([Textarea::make('reason')->label('What you checked')->required()->minLength(5)->maxLength(1000)])
                ->action($decide(AttendanceReview::ACCEPT)),
            Action::make('reject')->label('Reject')->color('danger')->icon('heroicon-o-x-mark')->visible($visible)
                ->modalDescription('The punch does not count. The employee\'s correct time, if any, is entered as manual attendance.')
                ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                ->action($decide(AttendanceReview::REJECT)),
        ];
    }
}
