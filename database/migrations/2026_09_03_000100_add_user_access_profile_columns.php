<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit, administrator-managed data visibility per user.
 *
 * Visibility was previously inferred from the single free-text `department`
 * column, so a user could belong to exactly one department and cross-functional
 * staff had to be given a broader role to see a second one. These two columns
 * make the decision explicit and multi-valued:
 *
 * - `allowed_departments`     departments whose dashboards/reports the user may see
 * - `allowed_data_source_ids` connected platforms the user may see, or null for
 *                             "no per-user platform restriction"
 *
 * Both narrow visibility on top of the existing role permissions. Neither ever
 * widens it, and administrators bypass both.
 *
 * The backfill is deliberately behaviour-preserving: each existing account
 * starts with exactly the one department it already had, so nobody gains or
 * loses visibility on release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('allowed_departments')->nullable()->after('department');
            $table->json('allowed_data_source_ids')->nullable()->after('allowed_departments');
        });

        DB::table('users')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->orderBy('id')
            ->select(['id', 'department'])
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['allowed_departments' => json_encode([$user->department])]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['allowed_departments', 'allowed_data_source_ids']);
        });
    }
};
