<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Notifications\Notification;

/**
 * For create pages: the "Created" message carries a Print link, so the slip or bill is printed
 * straight after saving. The page sets `protected string $printType` (a RecordDocuments key).
 */
trait OffersPrintAfterCreate
{
    protected function getCreatedNotification(): ?Notification
    {
        return PrintButton::saved($this->printType, $this->getRecord(), $this->getCreatedNotificationTitle() ?? 'Created');
    }
}
