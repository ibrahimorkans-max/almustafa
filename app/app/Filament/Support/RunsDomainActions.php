<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Shared\BusinessRuleViolation;
use Filament\Notifications\Notification;

/**
 * For record pages whose buttons call a domain service: a refused business rule becomes a
 * red notification, success refreshes the record.
 */
trait RunsDomainActions
{
    protected function runDomain(callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        $this->record = $this->record->fresh();
        Notification::make()->title($success)->success()->send();
    }
}
