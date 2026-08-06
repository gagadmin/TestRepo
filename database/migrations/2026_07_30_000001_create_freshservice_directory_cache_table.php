<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freshservice_directory_cache', function (Blueprint $table) {
            $table->id();
            // Index names are set explicitly: the generated defaults exceed
            // MySQL's 64 character identifier limit for this table name.
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->enum('entity_type', ['agent', 'group']);
            $table->unsignedBigInteger('entity_id');
            $table->string('name');
            $table->json('data')->nullable()->comment('Additional metadata from API');
            $table->timestamp('cached_at')->useCurrent();
            $table->timestamp('refreshed_at')->nullable();

            $table->unique(['data_source_id', 'entity_type', 'entity_id'], 'fs_dir_cache_unique');
            $table->index(['data_source_id', 'entity_type'], 'fs_dir_cache_source_type_idx');
            $table->index('entity_id', 'fs_dir_cache_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freshservice_directory_cache');
    }
};
