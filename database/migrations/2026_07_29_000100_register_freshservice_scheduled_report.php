<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dashboardId = DB::table('dashboards')->where('slug', 'itsm')->value('id');

        DB::table('data_sources')
            ->where('type', 'freshservice')
            ->where('status', 'connected')
            ->orderBy('id')
            ->each(function (object $source) use ($dashboardId) {
                $name = str_ends_with(strtolower($source->name), 'itsm')
                    ? "{$source->name} Summary"
                    : "{$source->name} ITSM Summary";
                $definition = [
                    'source_id' => $source->id,
                    'columns' => [
                        ['key' => 'section', 'label' => 'Section', 'type' => 'text'],
                        ['key' => 'metric', 'label' => 'Metric', 'type' => 'text'],
                        ['key' => 'detail', 'label' => 'Detail', 'type' => 'text'],
                        ['key' => 'count', 'label' => 'Ticket Count', 'type' => 'number'],
                    ],
                    'chart' => [
                        'type' => 'bar',
                        'title' => 'Freshservice ticket summary',
                        'category_key' => 'metric',
                        'value_key' => 'count',
                    ],
                    'filters' => ['date_from', 'date_to'],
                    'department' => 'Information Technology',
                    'allowed_departments' => ['Information Technology'],
                    'allowed_roles' => ['administrator', 'executive'],
                    'provisioned_by' => '2026_07_29_000100_register_freshservice_scheduled_report',
                ];
                $existing = DB::table('reports')
                    ->where('user_id', $source->owner_id)
                    ->where('type', 'itsm_ticket_summary')
                    ->where('name', $name)
                    ->first();
                $now = now();

                if ($existing) {
                    $reportId = $existing->id;
                    DB::table('reports')->where('id', $reportId)->update([
                        'description' => 'Current ticket backlog, SLA exposure, priority, status, group, and agent workload summary.',
                        'definition' => json_encode($definition),
                        'visibility' => 'department',
                        'updated_at' => $now,
                    ]);
                } else {
                    $reportId = DB::table('reports')->insertGetId([
                        'user_id' => $source->owner_id,
                        'name' => $name,
                        'type' => 'itsm_ticket_summary',
                        'description' => 'Current ticket backlog, SLA exposure, priority, status, group, and agent workload summary.',
                        'definition' => json_encode($definition),
                        'visibility' => 'department',
                        'last_generated_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if ($dashboardId) {
                    DB::table('dashboard_report')->updateOrInsert(
                        ['dashboard_id' => $dashboardId, 'report_id' => $reportId],
                        [
                            'sort_order' => 0,
                            'widget_size' => 'wide',
                            'settings' => json_encode(['show_table' => true]),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        DB::table('reports')
            ->where('type', 'itsm_ticket_summary')
            ->orderBy('id')
            ->get(['id', 'definition'])
            ->each(function (object $report) {
                $definition = json_decode($report->definition, true);

                if (($definition['provisioned_by'] ?? null) === '2026_07_29_000100_register_freshservice_scheduled_report') {
                    DB::table('reports')->where('id', $report->id)->delete();
                }
            });
    }
};
