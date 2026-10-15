<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\AccessCatalogSync;
use App\Domain\Access\EntityProvisioner;
use App\Domain\Access\RoleTemplates;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local/demo data: Al Mustafa Rice Mills (Lodhran) with one user per role, plus one Owner.
 * Passwords are random and written to storage/app/private/demo-credentials.txt
 * (git-ignored). Every demo user must change the password at first sign-in.
 * Refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    private const MILLS = [
        ['code' => 'AMR', 'name' => 'Al Mustafa Rice Mills', 'legal_name' => 'Al Mustafa Rice Mills',
            'address' => 'Adda Permat, 1 km Shujabad Road', 'city' => 'Lodhran', 'province' => 'Punjab', 'phone' => '0300-6834588'],
    ];

    public function run(TenantContext $tenant, EntityProvisioner $provisioner, AccessCatalogSync $catalog): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        $lines = ['# Demo credentials generated '.now()->toDateTimeString().' — local use only', ''];

        $tenant->runAsSystem(function () use ($provisioner, $catalog, &$lines): void {
            $entities = [];
            foreach (self::MILLS as $mill) {
                $entities[$mill['code']] = Entity::query()->where('code', $mill['code'])->first()
                    ?? $provisioner->create($mill + ['status' => Entity::STATUS_ACTIVE]);
            }

            $first = reset($entities);
            $lines[] = $this->makeAccount($first, $catalog->ownerRole(), 'owner', 'Demo Owner');

            foreach ($entities as $code => $entity) {
                foreach (RoleTemplates::entityRoleCodes() as $roleCode) {
                    $role = Role::query()->where('entity_id', $entity->id)->where('code', $roleCode)->firstOrFail();
                    $username = strtolower($code.'.'.str_replace('_', '', $roleCode));
                    $lines[] = $this->makeAccount($entity, $role, $username, ucwords(strtolower(str_replace('_', ' ', $roleCode))).' '.$code);
                }
            }
        });

        $path = storage_path('app/private/demo-credentials.txt');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode(PHP_EOL, array_filter($lines)).PHP_EOL);
        $this->command?->info("Demo users created. Credentials: {$path}");
    }

    private function makeAccount(Entity $entity, Role $role, string $username, string $name): string
    {
        if (LoginAccount::query()->where('username', $username)->exists()) {
            return "{$username}  (already existed, password unchanged)";
        }

        $password = Str::password(16);
        $person = new Person(['full_name' => $name]);
        $person->created_in_entity_id = $entity->id;
        $person->save();

        LoginAccount::query()->create([
            'entity_id' => $entity->id,
            'person_id' => $person->id,
            'role_id' => $role->id,
            'username' => $username,
            'password' => $password,
            'status' => LoginAccount::STATUS_ACTIVE,
            'must_change_password' => true,
        ]);

        return str_pad($username, 28).' '.$password.'   ('.$role->name.', '.$entity->code.')';
    }
}
