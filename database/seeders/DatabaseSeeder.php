<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminPassword = env('BI_ADMIN_PASSWORD');

        if (! $adminPassword && app()->environment('production')) {
            throw new \LogicException('BI_ADMIN_PASSWORD must be configured before production seeding.');
        }

        $permissions = collect([
            ['name' => 'dashboards.view', 'label' => 'View dashboards', 'group' => 'Dashboards'],
            ['name' => 'reports.view', 'label' => 'View reports', 'group' => 'Reports'],
            ['name' => 'reports.create', 'label' => 'Create reports', 'group' => 'Reports'],
            ['name' => 'reports.publish', 'label' => 'Publish enterprise reports', 'group' => 'Reports'],
            ['name' => 'reports.schedule', 'label' => 'Schedule reports', 'group' => 'Reports'],
            ['name' => 'ai.chat', 'label' => 'Use AI reporting chat', 'group' => 'AI'],
            ['name' => 'ai.web_search', 'label' => 'Use AI global web search', 'group' => 'AI'],
            ['name' => 'seo.view', 'label' => 'View SEO insights', 'group' => 'SEO'],
            ['name' => 'seo.generate', 'label' => 'Generate AI SEO action plans', 'group' => 'SEO'],
            ['name' => 'analytics.view', 'label' => 'View advanced analytics', 'group' => 'Analytics'],
            ['name' => 'analytics.run', 'label' => 'Generate advanced analytics', 'group' => 'Analytics'],
            ['name' => 'integrations.manage', 'label' => 'Manage integrations', 'group' => 'Administration'],
            ['name' => 'users.view', 'label' => 'View users', 'group' => 'Administration'],
            ['name' => 'users.manage', 'label' => 'Manage users', 'group' => 'Administration'],
            ['name' => 'audit.view', 'label' => 'View audit logs', 'group' => 'Administration'],
            ['name' => 'security.view', 'label' => 'View security dashboard', 'group' => 'Security'],
            ['name' => 'security.manage', 'label' => 'Acknowledge and resolve security events', 'group' => 'Security'],
        ])->mapWithKeys(function (array $attributes) {
            $permission = Permission::updateOrCreate(['name' => $attributes['name']], $attributes);

            return [$permission->name => $permission];
        });

        $roles = [
            'administrator' => [
                'label' => 'Administrator',
                'description' => 'Full platform administration and security access.',
                'permissions' => $permissions->keys()->all(),
            ],
            'executive' => [
                'label' => 'Executive',
                'description' => 'Enterprise dashboards and published reports.',
                'permissions' => ['dashboards.view', 'reports.view', 'ai.chat', 'analytics.view'],
            ],
            'manager' => [
                'label' => 'Manager',
                'description' => 'Department analytics and reports.',
                'permissions' => ['dashboards.view', 'reports.view', 'ai.chat', 'analytics.view'],
            ],
            'analyst' => [
                'label' => 'Analyst',
                'description' => 'Creates, analyzes, and schedules reports.',
                'permissions' => ['dashboards.view', 'reports.view', 'reports.create', 'reports.schedule', 'ai.chat', 'analytics.view', 'analytics.run'],
            ],
            'security_officer' => [
                'label' => 'Security Officer',
                'description' => 'Monitors security posture, investigates and resolves security events.',
                'permissions' => ['dashboards.view', 'audit.view', 'security.view', 'security.manage'],
            ],
        ];

        foreach ($roles as $name => $definition) {
            $role = Role::updateOrCreate(
                ['name' => $name],
                ['label' => $definition['label'], 'description' => $definition['description']]
            );
            $role->permissions()->sync($permissions->only($definition['permissions'])->pluck('id'));
        }

        $admin = User::updateOrCreate(
            ['email' => env('BI_ADMIN_EMAIL', 'jacob.calit@gaholding.com')],
            [
                'name' => env('BI_ADMIN_NAME', 'Platform Administrator'),
                'password' => Hash::make($adminPassword ?: 'ChangeMe123!'),
                'department' => 'Information Technology',
                'title' => 'System Administrator',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->roles()->sync([Role::where('name', 'administrator')->value('id')]);

        $reportDefinitions = [
            'sales' => [
                'name' => 'Sales Performance',
                'description' => 'Revenue, orders, and performance by period and region.',
                'columns' => [
                    ['key' => 'period', 'label' => 'Period', 'type' => 'date'],
                    ['key' => 'region', 'label' => 'Region', 'type' => 'text'],
                    ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'currency'],
                    ['key' => 'orders', 'label' => 'Orders', 'type' => 'number'],
                ],
                'chart' => ['type' => 'bar', 'title' => 'Revenue by period', 'category_key' => 'period', 'value_key' => 'revenue'],
                'departments' => ['Sales', 'Marketing'],
                'roles' => ['executive'],
            ],
            'crm_pipeline' => [
                'name' => 'CRM Pipeline',
                'description' => 'Opportunity value and deal volume by sales stage.',
                'columns' => [
                    ['key' => 'stage', 'label' => 'Stage', 'type' => 'text'],
                    ['key' => 'opportunities', 'label' => 'Opportunities', 'type' => 'number'],
                    ['key' => 'value', 'label' => 'Pipeline Value', 'type' => 'currency'],
                    ['key' => 'win_rate', 'label' => 'Win Rate', 'type' => 'percentage'],
                ],
                'chart' => ['type' => 'donut', 'title' => 'Pipeline by stage', 'category_key' => 'stage', 'value_key' => 'value'],
                'departments' => ['Sales'],
                'roles' => ['executive'],
            ],
            'website_analytics' => [
                'name' => 'Website Analytics',
                'description' => 'Traffic, engagement, and conversion performance.',
                'columns' => [
                    ['key' => 'period', 'label' => 'Period', 'type' => 'date'],
                    ['key' => 'sessions', 'label' => 'Sessions', 'type' => 'number'],
                    ['key' => 'users', 'label' => 'Users', 'type' => 'number'],
                    ['key' => 'conversions', 'label' => 'Conversions', 'type' => 'number'],
                ],
                'chart' => ['type' => 'area', 'title' => 'Sessions trend', 'category_key' => 'period', 'value_key' => 'sessions'],
                'departments' => ['Marketing'],
                'roles' => ['executive'],
            ],
            'asset_inventory' => [
                'name' => 'Asset Inventory',
                'description' => 'Asset counts, value, condition, and ownership.',
                'columns' => [
                    ['key' => 'category', 'label' => 'Category', 'type' => 'text'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                    ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
                    ['key' => 'book_value', 'label' => 'Book Value', 'type' => 'currency'],
                ],
                'chart' => ['type' => 'bar', 'title' => 'Assets by category', 'category_key' => 'category', 'value_key' => 'quantity'],
                'departments' => ['Operations', 'Asset Management'],
                'roles' => ['executive'],
            ],
            'procurement_spend' => [
                'name' => 'Procurement Spend',
                'description' => 'Spend by supplier, category, and procurement status.',
                'columns' => [
                    ['key' => 'supplier', 'label' => 'Supplier', 'type' => 'text'],
                    ['key' => 'category', 'label' => 'Category', 'type' => 'text'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                    ['key' => 'spend', 'label' => 'Spend', 'type' => 'currency'],
                ],
                'chart' => ['type' => 'bar', 'title' => 'Spend by supplier', 'category_key' => 'supplier', 'value_key' => 'spend'],
                'departments' => ['Procurement', 'Finance', 'Operations'],
                'roles' => ['executive'],
            ],
            'executive_kpi' => [
                'name' => 'Executive KPI',
                'description' => 'Enterprise-level KPI scorecard for leadership.',
                'columns' => [
                    ['key' => 'metric', 'label' => 'Metric', 'type' => 'text'],
                    ['key' => 'actual', 'label' => 'Actual', 'type' => 'number'],
                    ['key' => 'target', 'label' => 'Target', 'type' => 'number'],
                    ['key' => 'variance', 'label' => 'Variance', 'type' => 'percentage'],
                ],
                'chart' => ['type' => 'bar', 'title' => 'Actual vs target', 'category_key' => 'metric', 'value_key' => 'actual'],
                'departments' => [],
                'roles' => ['executive'],
            ],
            'financial_overview' => [
                'name' => 'Financial Overview',
                'description' => 'Revenue, expense, margin, and budget overview.',
                'columns' => [
                    ['key' => 'period', 'label' => 'Period', 'type' => 'date'],
                    ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'currency'],
                    ['key' => 'expenses', 'label' => 'Expenses', 'type' => 'currency'],
                    ['key' => 'margin', 'label' => 'Margin', 'type' => 'percentage'],
                ],
                'chart' => ['type' => 'line', 'title' => 'Financial trend', 'category_key' => 'period', 'value_key' => 'revenue'],
                'departments' => ['Finance'],
                'roles' => ['executive'],
            ],
            'itsm_ticket_summary' => [
                'name' => 'Freshservice ITSM Summary',
                'description' => 'Current ticket backlog, SLA exposure, priority, status, group, and agent workload summary.',
                'columns' => [
                    ['key' => 'section', 'label' => 'Section', 'type' => 'text'],
                    ['key' => 'metric', 'label' => 'Metric', 'type' => 'text'],
                    ['key' => 'detail', 'label' => 'Detail', 'type' => 'text'],
                    ['key' => 'count', 'label' => 'Ticket Count', 'type' => 'number'],
                ],
                'chart' => ['type' => 'bar', 'title' => 'Freshservice ticket summary', 'category_key' => 'metric', 'value_key' => 'count'],
                'departments' => ['Information Technology'],
                'roles' => ['executive'],
            ],
        ];

        $reports = collect($reportDefinitions)->mapWithKeys(function (array $definition, string $type) use ($admin) {
            $sourceId = $type === 'itsm_ticket_summary'
                ? DataSource::query()
                    ->where('type', 'freshservice')
                    ->where('status', 'connected')
                    ->value('id')
                : null;
            $report = Report::updateOrCreate(
                ['user_id' => $admin->id, 'type' => $type, 'name' => $definition['name']],
                [
                    'description' => $definition['description'],
                    'visibility' => $type === 'executive_kpi' ? 'enterprise' : 'department',
                    'definition' => [
                        'source_id' => $sourceId,
                        'columns' => $definition['columns'],
                        'chart' => $definition['chart'],
                        'filters' => ['date_from', 'date_to', 'department', 'region', 'status'],
                        'department' => $definition['departments'][0] ?? null,
                        'allowed_departments' => $definition['departments'],
                        'allowed_roles' => ['administrator', ...$definition['roles']],
                    ],
                ]
            );

            return [$type => $report];
        });

        $dashboards = [
            'executive' => ['name' => 'Executive', 'department' => null, 'reports' => ['executive_kpi', 'financial_overview', 'sales']],
            'finance' => ['name' => 'Finance', 'department' => 'Finance', 'reports' => ['financial_overview', 'procurement_spend']],
            'sales' => ['name' => 'Sales', 'department' => 'Sales', 'reports' => ['sales', 'crm_pipeline']],
            'operations' => ['name' => 'Operations', 'department' => 'Operations', 'reports' => ['asset_inventory', 'procurement_spend']],
            'procurement' => ['name' => 'Procurement', 'department' => 'Procurement', 'reports' => ['procurement_spend']],
            'asset-management' => ['name' => 'Asset Management', 'department' => 'Asset Management', 'reports' => ['asset_inventory']],
            'itsm' => ['name' => 'Freshservice ITSM', 'department' => 'Information Technology', 'reports' => ['itsm_ticket_summary']],
            'marketing' => ['name' => 'Marketing', 'department' => 'Marketing', 'reports' => ['website_analytics', 'sales']],
        ];

        // Security is provisioned separately: it is driven by the security
        // monitoring services rather than by report widgets, and is restricted
        // to IT and the security roles.
        Dashboard::updateOrCreate(
            ['slug' => 'security'],
            [
                'name' => 'System Security Dashboard',
                'department' => 'Information Technology',
                'description' => 'Real-time security posture, threat detection, identity risk, and incident response.',
                'visibility' => 'department',
                'layout' => [
                    'columns' => 12,
                    'allowed_roles' => ['administrator', 'security_officer'],
                ],
                'is_active' => true,
            ]
        );

        foreach ($dashboards as $slug => $definition) {
            $dashboard = Dashboard::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $definition['name'].' Dashboard',
                    'department' => $definition['department'],
                    'description' => "Operational visibility for {$definition['name']} stakeholders.",
                    'visibility' => $slug === 'executive' ? 'enterprise' : 'department',
                    'layout' => [
                        'columns' => 12,
                        'allowed_roles' => ['administrator', 'executive'],
                    ],
                    'is_active' => true,
                ]
            );
            $dashboard->reports()->sync(
                collect($definition['reports'])->mapWithKeys(
                    fn (string $type, int $index) => [
                        $reports[$type]->id => [
                            'sort_order' => $index,
                            'widget_size' => $index === 0 ? 'wide' : 'medium',
                            'settings' => json_encode(['show_table' => true]),
                        ],
                    ]
                )->all()
            );
        }
    }
}
