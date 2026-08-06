<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-property SEO profile: the categories and target regions that scope the
 * insights and (in Phase 4) the AI web-research. One row per Search Console
 * DataSource.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->unique()->constrained()->cascadeOnDelete();

            // e.g. ["automotive", "spare parts", "export cars"]
            $table->json('categories')->nullable();
            // e.g. [{"name":"United Arab Emirates","code":"AE"}]
            $table->json('regions')->nullable();
            // Optional known competitor domains and brand terms.
            $table->json('competitor_seeds')->nullable();
            $table->json('brand_terms')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_profiles');
    }
};
