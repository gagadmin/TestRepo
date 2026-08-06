<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the OpenAI web search tool usable end to end:
 *
 *   1. ensures the `ai.web_search` permission exists;
 *   2. grants it to the administrator role;
 *   3. sets a default model and enables the `web_search_openai` tool.
 *
 * The OpenAI API key itself is read from configuration (OPENAI_API_KEY) at
 * runtime and is not stored here. Idempotent and additive (see AGENTS.md): safe
 * to run once; it will not duplicate rows or clobber an admin's chosen model.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // 1. Ensure the permission exists.
        $permissionId = DB::table('permissions')->where('name', 'ai.web_search')->value('id');

        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'ai.web_search',
                'label' => 'Use AI global web search',
                'group' => 'AI',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2. Grant it to the administrator role.
        $roleId = DB::table('roles')->where('name', 'administrator')->value('id');

        if ($roleId && ! DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->where('role_id', $roleId)
            ->exists()
        ) {
            DB::table('permission_role')->insert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 3. Set a default model (only if unset) and enable the tool.
        $tool = DB::table('ai_tools')->where('name', 'web_search_openai')->first();

        if ($tool) {
            $options = json_decode($tool->options ?? '{}', true) ?: [];

            if (empty($options['model'])) {
                $options['model'] = 'gpt-4o';
            }
            $options['max_output_tokens'] = $options['max_output_tokens'] ?? 1500;
            $options['tool_type'] = $options['tool_type'] ?? 'web_search';

            DB::table('ai_tools')->where('id', $tool->id)->update([
                'is_enabled' => true,
                'options' => json_encode($options),
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Disable the tool; leave the permission in place (harmless if unused).
        DB::table('ai_tools')->where('name', 'web_search_openai')->update(['is_enabled' => false]);

        $permissionId = DB::table('permissions')->where('name', 'ai.web_search')->value('id');
        $roleId = DB::table('roles')->where('name', 'administrator')->value('id');

        if ($permissionId && $roleId) {
            DB::table('permission_role')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->delete();
        }
    }
};
