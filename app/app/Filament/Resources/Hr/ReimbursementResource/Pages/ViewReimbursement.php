<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\ReimbursementResource\Pages;

use App\Filament\Resources\Hr\ReimbursementResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReimbursement extends ViewRecord
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = ReimbursementResource::class;
}
