<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;

/**
 * The message after raising an approval request. The Owner's and a Super Admin's requests are
 * applied at once (SelfApproval), so "sent for approval" would be wrong for them.
 */
final class ApprovalNotice
{
    public static function send(ApprovalRequest $request, string $pendingTitle, ?string $pendingBody = null, bool $link = true): void
    {
        $open = $link ? [NotificationAction::make('open')->url(ApprovalRequestResource::getUrl('view', ['record' => $request]))] : [];

        match ($request->status) {
            ApprovalRequest::STATUS_EXECUTED => Notification::make()->success()->title('Done: applied at once')
                ->body('No second approval needed for the Owner or the mill\'s Super Admin. It is recorded in the history.')->actions($open)->send(),
            ApprovalRequest::STATUS_FAILED => Notification::make()->danger()->title('Could not be applied')->body($request->error)->actions($open)->send(),
            default => Notification::make()->success()->title($pendingTitle)->body($pendingBody)->actions($open)->send(),
        };
    }
}
