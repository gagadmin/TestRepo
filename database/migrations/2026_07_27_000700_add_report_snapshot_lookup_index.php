<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_snapshots', function (Blueprint $table) {
            $table->index(['report_id', 'generated_at'], 'report_snapshots_report_generated_index');
        });
    }

    public function down(): void
    {
        Schema::table('report_snapshots', function (Blueprint $table) {
            $table->dropIndex('report_snapshots_report_generated_index');
        });
    }
};
