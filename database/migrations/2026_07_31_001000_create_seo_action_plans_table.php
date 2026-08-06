<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-generated SEO action plans. Each plan is the model's sequenced explanation
 * of the deterministic findings at a point in time — stored so it is auditable
 * and reproducible. The figures live in the findings the plan references; the
 * plan itself carries actions, not invented metrics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_action_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('summary')->nullable();
            $table->json('items')->nullable();
            // Hash of the deterministic findings the plan was built from, so an
            // identical re-run is detectable and plans are traceable to inputs.
            $table->string('inputs_digest', 64)->index();
            $table->string('model')->nullable();
            $table->string('provider')->nullable();
            $table->timestamps();

            $table->index(['data_source_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_action_plans');
    }
};
