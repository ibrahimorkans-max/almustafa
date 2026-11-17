<?php

declare(strict_types=1);

namespace App\Filament\Resources\LoginAccountResource\Pages;

use App\Domain\Auth\SessionService;
use App\Filament\Concerns\TranslatesLabels;
use App\Filament\Resources\LoginAccountResource;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditLoginAccount extends EditRecord
{
    use TranslatesLabels;

    protected static string $resource = LoginAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var LoginAccount $record */
        $record = $this->getRecord();
        $phone = $record->person?->phone;

        return $data + ['security_email' => $record->email ?? $record->person?->email, 'security_phone' => PakistanIds::formatMobile($phone) ?? $phone];
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
            // The e-mail every security code goes to: a new one is confirmed again by its next code.
            $email = filled($data['security_email'] ?? null) ? mb_strtolower(trim((string) $data['security_email'])) : null;
            if ($email !== null && $email !== $record->email) {
                $record->forceFill(['email' => $email, 'email_verified_at' => null]);
            }
            $record->save();
            $phone = filled($data['security_phone'] ?? null) ? PakistanIds::normalizeMobile((string) $data['security_phone']) : null;
            if ($phone !== null && $record->person !== null && $phone !== $record->person->phone) {
                $record->person->phone = $phone;
                $record->person->save();
            }

            // New permissions take effect on next sign-in; end current sessions so nobody keeps old rights.
            if ($roleChanged) {
                app(SessionService::class)->revokeAllFor($record, 'role_changed');
            }

            return $record;
        });
    }
}
