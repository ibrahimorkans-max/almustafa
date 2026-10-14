<?php

declare(strict_types=1);

namespace App\Filament\Resources\LoginAccountResource\Pages;

use App\Domain\Auth\SessionService;
use App\Filament\Resources\LoginAccountResource;
use App\Models\LoginAccount;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditLoginAccount extends EditRecord
{
    protected static string $resource = LoginAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var LoginAccount $record */
        abort_unless(array_key_exists($data['role_id'], LoginAccountResource::assignableRoles()), 403);
        abort_if($record->person_id === auth()->user()?->person_id && $record->role_id !== $data['role_id'], 403, 'You cannot change your own role.');

        return DB::transaction(function () use ($record, $data): LoginAccount {
            $roleChanged = $record->role_id !== $data['role_id'];
            $record->role_id = $data['role_id'];
            // Nobody relaxes the device rule on their own account (the field is disabled for them too).
            if (array_key_exists('require_trusted_device', $data) && $record->person_id !== auth()->user()?->person_id) {
                $record->require_trusted_device = (bool) $data['require_trusted_device'];
            }
            $record->save();

            // New permissions take effect on next sign-in; end current sessions so nobody keeps old rights.
            if ($roleChanged) {
                app(SessionService::class)->revokeAllFor($record, 'role_changed');
            }

            return $record;
        });
    }
}
