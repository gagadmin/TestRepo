<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('data');
            $table->json('summary')->nullable();
            $table->json('citations')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('generated_at')->index();
            $table->timestamps();
        });

        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('department')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('visibility')->default('enterprise')->index();
            $table->json('layout')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('dashboard_report', function (Blueprint $table) {
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('widget_size')->default('medium');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->primary(['dashboard_id', 'report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_report');
        Schema::dropIfExists('dashboards');
        Schema::dropIfExists('report_snapshots');
    }
};
