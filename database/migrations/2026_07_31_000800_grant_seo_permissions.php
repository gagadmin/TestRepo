<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ensures the SEO permissions exist and are granted to the administrator role,
 * so the feature is usable after `php artisan migrate` without a full reseed.
 * Idempotent and additive.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'seo.view', 'label' => 'View SEO insights', 'group' => 'SEO'],
        ['name' => 'seo.generate', 'label' => 'Generate AI SEO action plans', 'group' => 'SEO'],
    ];

    public function up(): void
    {
        $now = now();
        $roleId = DB::table('roles')->where('name', 'administrator')->value('id');

        foreach (self::PERMISSIONS as $permission) {
            $id = DB::table('permissions')->where('name', $permission['name'])->value('id');

            if (! $id) {
                $id = DB::table('permissions')->insertGetId([
                    ...$permission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($roleId && ! DB::table('permission_role')
                ->where('permission_id', $id)->where('role_id', $roleId)->exists()
            ) {
                DB::table('permission_role')->insert([
                    'permission_id' => $id,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
