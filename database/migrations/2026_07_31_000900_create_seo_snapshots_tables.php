<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical Search Console snapshots — the store that makes ranking trends and
 * decline detection possible (GSC keeps no history for you, and the connector
 * otherwise persists nothing).
 *
 * A snapshot is one trailing-window capture for one property and dimension;
 * its rows are the per-keyword / per-page metrics at that time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('site_url')->nullable();
            $table->string('dimension', 16); // query | page
            $table->date('captured_on');     // the window's end (freshest complete day)
            $table->date('window_from');
            $table->date('window_to');
            $table->json('summary')->nullable();
            $table->timestamps();

            // Idempotent nightly capture: one snapshot per property/day/dimension.
            $table->unique(['data_source_id', 'captured_on', 'dimension']);
            $table->index(['data_source_id', 'dimension', 'captured_on']);
        });

        Schema::create('seo_snapshot_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('key', 512);          // the query text or page URL
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 2)->default(0);       // percentage
            $table->decimal('position', 6, 2)->default(0);
            $table->timestamps();

            $table->index('seo_snapshot_id');
            $table->index(['seo_snapshot_id', 'impressions']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_snapshot_rows');
        Schema::dropIfExists('seo_snapshots');
    }
};
