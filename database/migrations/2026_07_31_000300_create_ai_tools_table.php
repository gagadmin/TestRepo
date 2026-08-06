<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the AI tool allow list out of code and into configuration.
 *
 * The list was hard-coded in ToolRegistry's constructor, so connecting a new
 * source type to the assistant required a code change and a deploy. That is why
 * Freshservice appeared as "connected" in Data Sources while the assistant
 * insisted no ITSM connector existed.
 *
 * Security note: `handler` is NOT free text at the application level. It is
 * validated against a set of handlers implemented in code, so an administrator
 * composes approved capabilities rather than defining new behaviour. Target URLs
 * still come from the DataSource row and still pass through IntegrationUrlGuard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tools', function (Blueprint $table) {
            $table->id();

            // The function name exposed to the model.
            $table->string('name', 64)->unique();
            $table->string('label');

            // Sent verbatim to the model, so it decides when to call the tool.
            $table->text('description');

            // Which code-implemented retrieval strategy runs.
            $table->string('handler', 48)->index();

            // DataSource types this tool may read.
            $table->json('source_types');

            $table->boolean('is_enabled')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Reserved for handler-specific options (page caps, default limits).
            $table->json('options')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->seedTools();
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tools');
    }

    /**
     * Seed the five tools that were previously hard-coded, so behaviour is
     * unchanged on migrate, plus the ITSM tool that was missing.
     */
    private function seedTools(): void
    {
        $now = now();

        $tools = [
            [
                'name' => 'get_sales_report',
                'label' => 'Sales performance',
                'description' => 'Retrieve grounded sales performance data from an approved ERP, SAP, or internal reporting source.',
                'handler' => 'generic_http',
                'source_types' => ['erp', 'sap', 'internal_application'],
                'sort_order' => 10,
            ],
            [
                'name' => 'get_asset_summary',
                'label' => 'Asset inventory',
                'description' => 'Retrieve grounded asset inventory and condition data from an approved asset, ERP, or SAP source.',
                'handler' => 'generic_http',
                'source_types' => ['asset_management', 'erp', 'sap'],
                'sort_order' => 20,
            ],
            [
                'name' => 'get_procurement_report',
                'label' => 'Procurement spend',
                'description' => 'Retrieve grounded procurement spend and supplier data from an approved procurement, ERP, or SAP source.',
                'handler' => 'generic_http',
                'source_types' => ['procurement', 'erp', 'sap'],
                'sort_order' => 30,
            ],
            [
                'name' => 'get_website_analytics',
                'label' => 'Website analytics',
                'description' => 'Retrieve grounded website traffic and conversion data from an approved analytics source.',
                'handler' => 'google_search_console',
                'source_types' => ['website_analytics', 'google_search_console'],
                'sort_order' => 40,
            ],
            [
                'name' => 'get_crm_pipeline',
                'label' => 'CRM pipeline',
                'description' => 'Retrieve grounded sales-pipeline and opportunity data from an approved CRM source.',
                'handler' => 'generic_http',
                'source_types' => ['crm'],
                'sort_order' => 50,
            ],
            [
                // The tool that was missing. Its description tells the model
                // exactly which questions it answers, including "today".
                'name' => 'get_itsm_ticket_summary',
                'label' => 'ITSM ticket summary',
                'description' => 'Retrieve grounded IT service desk ticket data from an approved Freshservice ITSM source. '
                    .'Answers questions about ticket volumes and backlog: how many tickets or service requests were '
                    .'created in a period, counts by status, priority, type (incident vs service request), support '
                    .'group, category, and assigned agent, plus unresolved, overdue, on-hold and SLA-breached counts. '
                    .'Pass date_from and date_to to scope to a period such as today or this month.',
                'handler' => 'freshservice_analytics',
                'source_types' => ['freshservice'],
                'sort_order' => 60,
            ],
        ];

        foreach ($tools as $tool) {
            // Idempotent: re-running must not duplicate or clobber an admin edit.
            if (DB::table('ai_tools')->where('name', $tool['name'])->exists()) {
                continue;
            }

            DB::table('ai_tools')->insert([
                ...$tool,
                'source_types' => json_encode($tool['source_types']),
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
