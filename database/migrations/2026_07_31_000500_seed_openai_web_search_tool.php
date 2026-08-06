<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the OpenAI-backed web search tool (disabled) so it appears in AI Tools
 * ready to enable. It reuses the application's configured OpenAI API key, so no
 * per-tool endpoint or key is stored — only the model to use.
 *
 * Additive and idempotent (see AGENTS.md): a separate migration from the
 * secret_options change so neither edits an already-applied migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('ai_tools')->where('name', 'web_search_openai')->exists()) {
            return;
        }

        DB::table('ai_tools')->insert([
            'name' => 'web_search_openai',
            'label' => 'Global web search (OpenAI)',
            'description' => 'Search the public web for current, general-knowledge facts that no connected '
                .'business data source covers, such as market news, public company facts, definitions, or '
                .'current events. Returns an answer with source URLs. Do not use it for internal company '
                .'figures — those come from the connected data sources.',
            'handler' => 'openai_web_search',
            'source_types' => json_encode([]),
            'is_enabled' => false,
            'sort_order' => 91,
            'options' => json_encode([
                'model' => null,
                'max_output_tokens' => 1500,
                'tool_type' => 'web_search',
            ]),
            'secret_options' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ai_tools')->where('name', 'web_search_openai')->delete();
    }
};
