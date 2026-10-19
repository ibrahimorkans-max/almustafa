<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Office Desk rights get neutral codes: archive.search → office.view, archive.restore → office.restore.
 * Every role keeps exactly the rights it had (the grants move to the new codes); nothing is added or taken
 * away. Row-level security is FORCEd, so this runs with the system bypass.
 */
return new class extends Migration
{
    private const RENAMES = ['archive.search' => 'office.view', 'archive.restore' => 'office.restore'];

    public function up(): void
    {
        $this->recode(self::RENAMES);
    }

    public function down(): void
    {
        $this->recode(array_flip(self::RENAMES));
    }

    /** @param  array<string, string>  $renames  old code => new code */
    private function recode(array $renames): void
    {
        DB::transaction(function () use ($renames): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            foreach ($renames as $old => $new) {
                $permission = DB::table('permissions')->where('code', $old)->first();
                if ($permission === null) {
                    continue; // fresh install: access:sync creates the new codes
                }
                if (! DB::table('permissions')->where('code', $new)->exists()) {
                    DB::table('permissions')->insert(['code' => $new] + array_diff_key((array) $permission, ['code' => true]));
                }
                // Move each grant; a role that somehow holds both keeps one.
                DB::table('role_permissions')->where('permission_code', $old)
                    ->whereIn('role_id', DB::table('role_permissions')->where('permission_code', $new)->select('role_id'))->delete();
                DB::table('role_permissions')->where('permission_code', $old)->update(['permission_code' => $new]);
                DB::table('permissions')->where('code', $old)->delete();
            }
        });
    }
};
