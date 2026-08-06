<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds encrypted storage for standalone-tool provider secrets, and seeds the
 * global web search tool (disabled) so it appears in AI Tools ready to configure.
 *
 * Reporting tools take their endpoint and credentials from a DataSource /
 * ApiConfiguration row. Standalone tools such as web_search have no DataSource,
 * so their provider secret needs a home. `secret_options` mirrors
 * ApiConfiguration.encrypted_credentials: an `encrypted:array` cast, hidden from
 * serialization, never audited. Non-secret provider settings (endpoint, allowed
 * hosts, limits) live in the existing plaintext `options` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_tools', function (Blueprint $table) {
            // Encrypted at the model layer (encrypted:array cast), so the raw
            // column is ciphertext. Text, like api_configurations.encrypted_*.
            $table->text('secret_options')->nullable()->after('options');
        });

        $this->seedWebSearchTool();
    }

    public function down(): void
    {
        Schema::table('ai_tools', function (Blueprint $table) {
            $table->dropColumn('secret_options');
        });
    }

    /**
     * Seed a disabled web_search tool. Disabled and with no provider configured,
     * it is inert until an administrator fills in the endpoint, allowed hosts and
     * API key on the AI Tools page and enables it.
     */
    private function seedWebSearchTool(): void
    {
        if (DB::table('ai_tools')->where('name', 'web_search')->exists()) {
            return;
        }

        DB::table('ai_tools')->insert([
            'name' => 'web_search',
            'label' => 'Global web search',
            'description' => 'Search the public web for current, general-knowledge facts that no connected '
                .'business data source covers. Use for public information such as market news, definitions, '
                .'public company facts, or current events. Returns titled results with source URLs. Do not use '
                .'it for internal company figures — those come from the connected data sources.',
            'handler' => 'web_search',
            // Standalone: reads no DataSource. Stored as an empty array so the
            // JSON column is valid and the model cast is happy.
            'source_types' => json_encode([]),
            'is_enabled' => false,
            'sort_order' => 90,
            'options' => json_encode([
                'endpoint' => null,
                'allowed_hosts' => [],
                'auth_scheme' => 'bearer',
                'key_header' => 'X-API-Key',
                'max_results' => 5,
                'timeout_seconds' => 15,
                'cache_seconds' => 300,
            ]),
            'secret_options' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
