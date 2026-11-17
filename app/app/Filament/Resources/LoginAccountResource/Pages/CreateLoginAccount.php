<?php

declare(strict_types=1);

namespace App\Filament\Resources\LoginAccountResource\Pages;

use App\Filament\Concerns\TranslatesLabels;
use App\Filament\Resources\LoginAccountResource;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Support\Values\PakistanIds;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateLoginAccount extends CreateRecord
{
    use TranslatesLabels;

    protected static string $resource = LoginAccountResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(array_key_exists($data['role_id'], LoginAccountResource::assignableRoles()), 403);

        return DB::transaction(function () use ($data): LoginAccount {
            $email = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;
            $person = Person::query()->create([
                'full_name' => $data['full_name'],
                'phone' => filled($data['phone'] ?? null) ? (PakistanIds::normalizeMobile((string) $data['phone']) ?? $data['phone']) : null,
                'email' => $email,
            ]);

            $account = LoginAccount::query()->create([
                'person_id' => $person->id,
                'role_id' => $data['role_id'],
                'username' => $data['username'],
                'password' => $data['password'],
                'status' => LoginAccount::STATUS_ACTIVE,
                'must_change_password' => true,
            ]);
            // The only address its security codes go to (confirmed by the first one, at the first sign-in).
            if ($email !== null) {
                $account->forceFill(['email' => $email])->save();
            }

            return $account;
        });
    }
}
