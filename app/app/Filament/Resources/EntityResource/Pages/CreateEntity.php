<?php

declare(strict_types=1);

namespace App\Filament\Resources\EntityResource\Pages;

use App\Domain\Access\EntityProvisioner;
use App\Filament\Resources\EntityResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEntity extends CreateRecord
{
    protected static string $resource = EntityResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(EntityProvisioner::class)->create($data);
    }
}
