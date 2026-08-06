<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correction memory and failure memory for the assistant.
 *
 * Important framing: a hosted model's weights never change. Nothing here
 * "trains" anything. These tables hold curated corrections that are injected
 * into the prompt on later questions, which produces learning-like behaviour
 * through retrieval.
 *
 * The approval gate is a security control, not bureaucracy. An unreviewed
 * correction is a prompt-injection vector: whatever text it contains reaches
 * the model as trusted guidance on every subsequent question, for every user.
 * One mistaken or malicious entry would otherwise spread a wrong figure
 * company-wide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_corrections', function (Blueprint $table) {
            $table->id();

            // What the user asked, and what came back. Encrypted: both can
            // contain business data, consistent with how messages are stored.
            $table->text('question');
            $table->text('incorrect_answer')->nullable();

            // The guidance injected into future prompts once approved.
            $table->text('correction');

            // Optional scoping so a correction only applies to relevant asks.
            $table->string('topic', 120)->nullable()->index();
            $table->json('applies_to_tools')->nullable();

            $table->string('status', 24)->default('pending')->index();

            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            // How often this correction has been injected, so low-value entries
            // can be pruned and high-value ones can be promoted.
            $table->unsignedInteger('applied_count')->default(0);
            $table->timestamp('last_applied_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        /**
         * Tool failure memory.
         *
         * The reported fault was the assistant claiming no ITSM connector
         * existed. Recording why a tool actually failed lets the assistant say
         * "the ITSM connector returned 403 on the agents endpoint" instead of
         * denying the capability exists at all.
         */
        Schema::create('ai_tool_failures', function (Blueprint $table) {
            $table->id();
            $table->string('tool_name', 64)->index();
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reason', 64)->index();
            $table->text('message');

            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_failed_at');
            $table->timestamp('last_failed_at')->index();

            // Stable key so a repeating failure updates one row.
            $table->string('fingerprint', 191)->unique();

            $table->boolean('resolved')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_failures');
        Schema::dropIfExists('ai_corrections');
    }
};
