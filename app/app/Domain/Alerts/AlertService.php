<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Alert;
use App\Models\LoginAccount;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Raises alerts for people to look at and notifies holders of the given permission in-app.
 * The same permission is needed to acknowledge the alert. Used by inventory/production now
 * and by anomaly detection in phase 8.
 */
final class AlertService
{
    public function __construct(private readonly ActorContext $actor) {}

    /** @param  array<string, mixed>  $data */
    public function raise(string $type, string $severity, string $title, ?string $body, ?Model $subject, array $data, string $permission): Alert
    {
        $alert = Alert::query()->create([
            'type' => $type, 'severity' => $severity, 'title' => mb_substr($title, 0, 255), 'body' => $body,
            'subject_type' => $subject?->getTable(), 'subject_id' => $subject?->getKey(),
            'data' => $data + ['permission' => $permission],
        ]);

        $recipients = LoginAccount::query()->where('entity_id', $alert->entity_id)->where('status', 'active')->get()
            ->filter(fn (LoginAccount $a) => $a->hasPermission($permission));
        if ($recipients->isNotEmpty()) {
            $notification = Notification::make()->title($alert->title)->body($body);
            ($severity === 'critical' ? $notification->danger() : $notification->warning())->sendToDatabase($recipients);
        }

        return $alert;
    }

    public function acknowledge(Alert $alert, string $note): Alert
    {
        $permission = (string) ($alert->data['permission'] ?? 'approval.decide');
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' to acknowledge this alert.");
        }
        if (mb_strlen(trim($note)) < 5) {
            throw new BusinessRuleViolation('Say what was found or done.');
        }
        if (! $alert->isOpen()) {
            throw new BusinessRuleViolation('This alert was already acknowledged.');
        }
        $alert->forceFill([
            'status' => 'acknowledged',
            'acknowledged_by' => $this->actor->accountId(),
            'acknowledged_at' => now(),
            'acknowledgement_note' => $note,
        ])->save();

        return $alert;
    }
}
