<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuthSessionResource\Pages;

use App\Filament\Resources\AuthSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListAuthSessions extends ListRecords
{
    protected static string $resource = AuthSessionResource::class;
}
