<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_insights', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->index();
            $table->string('severity')->default('info')->index();
            $table->string('metric_key')->nullable()->index();
            $table->string('title');
            $table->text('narrative');
            $table->text('payload')->nullable();
            $table->timestamp('generated_at')->index();
            $table->timestamps();
            $table->index(['report_id', 'generated_at'], 'analytics_report_generated_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_insights');
    }
};
