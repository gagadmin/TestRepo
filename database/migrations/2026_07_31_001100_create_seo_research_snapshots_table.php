<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI web-research snapshots (Phase 4). Each row is one cited research run for a
 * property, seeded by its categories + regions. This is qualitative,
 * web-gathered intelligence (competitors, backlink ideas, technical/content
 * signals) — NOT metric-grade data — always stored with source URLs and dated
 * for reproducibility. See ADR-002 §7A.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_research_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Hash of the profile + seed inputs, so identical runs are traceable.
            $table->string('profile_digest', 64)->index();
            // { competitors:[], backlink_targets:[], technical_signals:[], content_ideas:[] }
            $table->json('findings')->nullable();
            $table->string('model')->nullable();
            $table->string('provider')->nullable();
            $table->timestamps();

            $table->index(['data_source_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_research_snapshots');
    }
};
