<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditLog extends ViewRecord
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = AuditLogResource::class;
}
