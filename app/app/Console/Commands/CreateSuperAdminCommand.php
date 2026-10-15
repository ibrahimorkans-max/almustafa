<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\AccessCatalogSync;
use App\Domain\Access\EntityProvisioner;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Installation bootstrap: creates the first Owner (and a home entity if none exists).
 * The password is asked interactively and never passed on the command line.
 */
final class CreateSuperAdminCommand extends Command
{
    protected $signature = 'ricemill:create-owner {username} {--name=} {--entity-code=HQ}';

    protected $description = 'Create an Owner login account (installation bootstrap)';

    /** The name used before the Owner became the top role. */
    protected $aliases = ['ricemill:create-super-admin'];

    public function handle(TenantContext $tenant, AccessCatalogSync $catalog, EntityProvisioner $provisioner): int
    {
        $username = mb_strtolower((string) $this->argument('username'));
        $name = (string) ($this->option('name') ?: $this->ask('Full name'));
        $password = (string) $this->secret('Password (min 12 chars, mixed case, number, symbol)');

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            ['username' => ['required', 'regex:/^[a-z0-9._-]{3,64}$/'], 'password' => ['required', Password::defaults()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $catalog->sync();

        $tenant->runAsSystem(function () use ($username, $name, $password, $provisioner, $catalog): void {
            DB::transaction(function () use ($username, $name, $password, $provisioner, $catalog): void {
                $code = (string) $this->option('entity-code');
                $entity = Entity::query()->where('code', $code)->first()
                    ?? $provisioner->create(['code' => $code, 'name' => 'Head Office', 'status' => Entity::STATUS_ACTIVE]);

                $person = new Person(['full_name' => $name]);
                $person->created_in_entity_id = $entity->id;
                $person->save();

                LoginAccount::query()->create([
                    'entity_id' => $entity->id,
                    'person_id' => $person->id,
                    'role_id' => $catalog->ownerRole()->id,
                    'username' => $username,
                    'password' => $password,
                    'status' => LoginAccount::STATUS_ACTIVE,
                ]);
            });
        });

        $this->info("Owner '{$username}' created. 2FA enrollment is required at first sign-in.");

        return self::SUCCESS;
    }
}
