<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApprovalRequestResource\Pages;

use App\Filament\Resources\ApprovalRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListApprovalRequests extends ListRecords
{
    protected static string $resource = ApprovalRequestResource::class;
}
