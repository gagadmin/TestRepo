<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE report_schedules MODIFY recipients LONGTEXT NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE report_schedules ALTER COLUMN recipients TYPE TEXT USING recipients::text');
        }

        DB::table('report_schedules')->orderBy('id')->each(function (object $schedule) {
            $decoded = is_string($schedule->recipients) ? json_decode($schedule->recipients, true) : $schedule->recipients;
            DB::table('report_schedules')->where('id', $schedule->id)->update([
                'recipients' => Crypt::encryptString(json_encode($decoded, JSON_THROW_ON_ERROR)),
            ]);
        });

        Schema::table('report_schedules', function (Blueprint $table) {
            $table->string('format')->default('pdf')->after('timezone');
            $table->json('filters')->nullable()->after('format');
            $table->string('last_status')->nullable()->after('last_run_at');
            $table->unsignedInteger('failure_count')->default(0)->after('last_status');
            $table->text('last_error')->nullable()->after('failure_count');
        });

        Schema::create('report_schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('report_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('trigger')->default('scheduled');
            $table->json('channel_results')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['report_schedule_id', 'created_at'], 'schedule_runs_schedule_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedule_runs');

        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropColumn(['format', 'filters', 'last_status', 'failure_count', 'last_error']);
        });

        DB::table('report_schedules')->orderBy('id')->each(function (object $schedule) {
            DB::table('report_schedules')->where('id', $schedule->id)->update([
                'recipients' => Crypt::decryptString($schedule->recipients),
            ]);
        });

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE report_schedules MODIFY recipients JSON NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE report_schedules ALTER COLUMN recipients TYPE JSON USING recipients::json');
        }
    }
};
