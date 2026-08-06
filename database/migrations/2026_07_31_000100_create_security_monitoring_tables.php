<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('detector', 64)->index();
            $table->string('category', 48)->index();
            $table->string('severity', 16)->index();
            $table->string('title');
            $table->text('description');
            $table->string('status', 24)->default('open')->index();

            // Subject of the finding. Nullable because some findings are
            // system-wide (configuration drift) rather than user-scoped.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable()->index();

            // Stable key so a repeating condition updates one row instead of
            // creating duplicates on every scan.
            $table->string('fingerprint', 191)->unique();

            $table->unsignedInteger('occurrences')->default(1);
            $table->json('evidence')->nullable();
            $table->json('recommendation')->nullable();

            $table->timestamp('first_detected_at')->index();
            $table->timestamp('last_detected_at')->index();

            // Detected -> acknowledged -> resolved lifecycle, used for MTTD/MTTR.
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            $table->boolean('alerted')->default(false)->index();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['category', 'last_detected_at']);
        });

        Schema::create('security_scans', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 24)->default('scheduled');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('running')->index();
            $table->unsignedInteger('events_detected')->default(0);
            $table->unsignedInteger('events_created')->default(0);
            $table->unsignedInteger('detectors_run')->default(0);
            $table->unsignedSmallInteger('security_score')->nullable();
            $table->json('detector_results')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        $this->seedAuthorization();
    }

    public function down(): void
    {
        DB::table('dashboards')->where('slug', 'security')->delete();

        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['security.view', 'security.manage'])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        DB::table('roles')->where('name', 'security_officer')->delete();

        Schema::dropIfExists('security_scans');
        Schema::dropIfExists('security_events');
    }

    /**
     * Register the security permissions, the security officer role, and the
     * Security dashboard. Written additively so re-running is safe.
     */
    private function seedAuthorization(): void
    {
        $now = now();

        $permissions = [
            ['name' => 'security.view', 'label' => 'View security dashboard', 'group' => 'Security'],
            ['name' => 'security.manage', 'label' => 'Acknowledge and resolve security events', 'group' => 'Security'],
        ];

        $permissionIds = [];

        foreach ($permissions as $permission) {
            $existing = DB::table('permissions')->where('name', $permission['name'])->first();

            if ($existing) {
                DB::table('permissions')->where('id', $existing->id)->update([
                    'label' => $permission['label'],
                    'group' => $permission['group'],
                    'updated_at' => $now,
                ]);
                $permissionIds[$permission['name']] = $existing->id;

                continue;
            }

            $permissionIds[$permission['name']] = DB::table('permissions')->insertGetId([
                ...$permission,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Dedicated security role for staff who are not full platform admins.
        $securityRoleId = DB::table('roles')->where('name', 'security_officer')->value('id');

        if (! $securityRoleId) {
            $securityRoleId = DB::table('roles')->insertGetId([
                'name' => 'security_officer',
                'label' => 'Security Officer',
                'description' => 'Monitors security posture, investigates and resolves security events.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $administratorRoleId = DB::table('roles')->where('name', 'administrator')->value('id');

        $grants = [
            $securityRoleId => ['security.view', 'security.manage', 'audit.view', 'dashboards.view'],
            $administratorRoleId => ['security.view', 'security.manage'],
        ];

        foreach ($grants as $roleId => $names) {
            if (! $roleId) {
                continue;
            }

            foreach ($names as $name) {
                $permissionId = $permissionIds[$name]
                    ?? DB::table('permissions')->where('name', $name)->value('id');

                if (! $permissionId) {
                    continue;
                }

                $exists = DB::table('permission_role')
                    ->where('permission_id', $permissionId)
                    ->where('role_id', $roleId)
                    ->exists();

                if (! $exists) {
                    DB::table('permission_role')->insert([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        if (! DB::table('dashboards')->where('slug', 'security')->exists()) {
            DB::table('dashboards')->insert([
                'name' => 'System Security Dashboard',
                'slug' => 'security',
                'department' => 'Information Technology',
                'description' => 'Real-time security posture, threat detection, identity risk, and incident response.',
                'visibility' => 'department',
                'layout' => json_encode([
                    'columns' => 12,
                    'allowed_roles' => ['administrator', 'security_officer'],
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
