<?php

namespace Tests\Feature;

use App\Data\ConnectionResult;
use App\Models\ApiConfiguration;
use App\Models\DataSource;
use App\Models\Permission;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Services\Integrations\GoogleSearchConsoleService;
use App\Services\Integrations\IntegrationRequestFactory;
use App\Services\Integrations\IntegrationUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class IntegrationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_create_and_test_an_encrypted_data_source(): void
    {
        $user = $this->integrationAdministrator();

        $createResponse = $this->actingAs($user)->postJson('/api/integrations', [
            'name' => 'Regional CRM',
            'type' => 'crm',
            'description' => 'Sales opportunity and customer data.',
            'base_url' => 'https://api.example.com',
            'auth_type' => 'bearer',
            'credentials' => ['token' => 'crm-secret-token'],
            'headers' => ['X-Tenant' => 'regional'],
            'settings' => ['health_path' => '/health', 'data_path' => '/opportunities'],
            'timeout_seconds' => 15,
            'retry_count' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.has_credentials', true)
            ->assertJsonMissing(['token' => 'crm-secret-token']);

        $sourceId = $createResponse->json('data.id');
        $storedCredentials = DB::table('api_configurations')
            ->where('data_source_id', $sourceId)
            ->value('encrypted_credentials');

        $this->assertStringNotContainsString('crm-secret-token', $storedCredentials);

        Http::fake([
            'https://api.example.com/health' => Http::response(['status' => 'ok']),
        ]);

        $this->postJson("/api/integrations/{$sourceId}/test")
            ->assertOk()
            ->assertJsonPath('result.successful', true)
            ->assertJsonPath('data.status', 'connected');

        $this->assertDatabaseHas('integration_runs', [
            'data_source_id' => $sourceId,
            'operation' => 'connection_test',
            'status' => 'succeeded',
        ]);
    }

    public function test_updating_metadata_without_credentials_preserves_the_existing_secret(): void
    {
        $user = $this->integrationAdministrator();
        $source = DataSource::create([
            'name' => 'ERP',
            'type' => 'erp',
            'base_url' => 'https://erp.example.com',
            'status' => 'draft',
            'owner_id' => $user->id,
        ]);
        $source->apiConfiguration()->create([
            'auth_type' => 'api_key',
            'encrypted_credentials' => ['api_key' => 'preserved-secret'],
            'timeout_seconds' => 30,
            'retry_count' => 2,
        ]);

        $this->actingAs($user)->putJson("/api/integrations/{$source->id}", [
            'name' => 'Enterprise ERP',
            'type' => 'erp',
            'base_url' => 'https://erp.example.com',
            'auth_type' => 'api_key',
            'settings' => ['health_path' => '/health'],
            'timeout_seconds' => 20,
            'retry_count' => 1,
        ])->assertOk();

        $this->assertSame(
            'preserved-secret',
            $source->apiConfiguration->fresh()->encrypted_credentials['api_key']
        );
    }

    public function test_users_without_integration_permission_are_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->getJson('/api/integrations')
            ->assertForbidden();
    }

    public function test_private_network_targets_are_blocked_by_default(): void
    {
        config([
            'integrations.allow_private_networks' => false,
            'integrations.require_https' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(IntegrationUrlGuard::class)->assertAllowed('https://127.0.0.1/health');
    }

    public function test_integration_requests_never_follow_redirects(): void
    {
        $options = app(IntegrationRequestFactory::class)->make(null)->getOptions();

        $this->assertFalse($options['allow_redirects']);
    }

    public function test_stored_unsafe_api_key_headers_are_rejected_at_request_time(): void
    {
        $configuration = new ApiConfiguration([
            'auth_type' => 'api_key',
            'encrypted_credentials' => ['header' => 'Host', 'api_key' => 'secret'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The configured integration header name is not allowed.');

        app(IntegrationRequestFactory::class)->make($configuration);
    }

    public function test_unsafe_endpoint_and_header_overrides_are_rejected(): void
    {
        $user = $this->integrationAdministrator();

        $this->actingAs($user)->postJson('/api/integrations', [
            'name' => 'Unsafe source',
            'type' => 'crm',
            'base_url' => 'https://127.0.0.1',
            'auth_type' => 'none',
            'credentials' => ['api_key' => 'secret', 'header' => 'Host'],
            'headers' => ['Host' => 'internal.example', 'X-Test' => "safe\r\nInjected: true"],
            'settings' => ['health_path' => '/health'],
            'timeout_seconds' => 10,
            'retry_count' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['base_url', 'credentials.header', 'headers']);
    }

    public function test_an_integration_assigned_to_a_report_cannot_be_deleted(): void
    {
        $user = $this->integrationAdministrator();
        $source = DataSource::create([
            'name' => 'Assigned ERP',
            'type' => 'erp',
            'base_url' => 'https://erp.example.com',
            'status' => 'connected',
            'owner_id' => $user->id,
        ]);
        Report::create([
            'user_id' => $user->id,
            'name' => 'ERP report',
            'type' => 'custom',
            'visibility' => 'private',
            'definition' => [
                'source_id' => $source->id,
                'columns' => [['key' => 'value', 'label' => 'Value', 'type' => 'number']],
            ],
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/integrations/{$source->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'This data source is assigned to one or more reports and cannot be removed.');

        $this->assertDatabaseHas('data_sources', ['id' => $source->id]);
    }

    public function test_an_integration_administrator_can_test_search_console_safely(): void
    {
        $user = $this->integrationAdministrator();

        $this->mock(
            GoogleSearchConsoleService::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('testConnection')
                    ->once()
                    ->andReturn(new ConnectionResult(
                        successful: false,
                        message: 'Google Search Console rejected the site-list request.',
                        httpStatus: 403,
                        errorCode: 'api_error',
                        durationMs: 25,
                        context: [
                            'google_status' => 'PERMISSION_DENIED',
                            'google_reason' => 'accessNotConfigured',
                            'private_key' => 'must-never-be-returned',
                        ],
                    ));
            },
        );

        $this->actingAs($user)
            ->postJson('/api/integrations/search-console/test')
            ->assertUnprocessable()
            ->assertJsonPath('result.successful', false)
            ->assertJsonPath('result.error_code', 'api_error')
            ->assertJsonPath('result.context.google_reason', 'accessNotConfigured')
            ->assertJsonMissing(['private_key' => 'must-never-be-returned']);
    }

    public function test_search_console_test_requires_integration_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->postJson('/api/integrations/search-console/test')
            ->assertForbidden();
    }

    public function test_search_console_source_uses_specialized_connection_and_preview_flow(): void
    {
        $user = $this->integrationAdministrator();
        $source = DataSource::create([
            'name' => 'Aboudcar Search Console',
            'type' => 'google_search_console',
            'base_url' => 'https://www.googleapis.com/webmasters/v3',
            'status' => 'draft',
            'owner_id' => $user->id,
            'settings' => ['site_url' => 'https://www.aboudcar.com/'],
        ]);
        $source->apiConfiguration()->create([
            'auth_type' => 'none',
            'timeout_seconds' => 15,
            'retry_count' => 0,
        ]);

        $this->mock(
            GoogleSearchConsoleService::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('testConnection')
                    ->once()
                    ->with('https://www.aboudcar.com/')
                    ->andReturn(new ConnectionResult(
                        successful: true,
                        message: 'Google Search Console connection established.',
                        httpStatus: 200,
                        durationMs: 20,
                    ));
                $mock->shouldReceive('analytics')
                    ->once()
                    ->with(['dimension' => 'query', 'limit' => 10], 'https://www.aboudcar.com/')
                    ->andReturn([
                        'rows' => [[
                            'query' => 'used cars dubai',
                            'clicks' => 8,
                            'impressions' => 100,
                            'ctr' => 8.0,
                            'position' => 2.5,
                        ]],
                        'summary' => [
                            'site_url' => 'https://www.aboudcar.com/',
                            'date_from' => '2026-07-01',
                            'date_to' => '2026-07-27',
                            'dimension' => 'query',
                            'clicks' => 8,
                            'impressions' => 100,
                            'ctr' => 8.0,
                            'position' => 2.5,
                            'row_count' => 1,
                        ],
                    ]);
            },
        );

        $this->actingAs($user)
            ->postJson("/api/integrations/{$source->id}/test")
            ->assertOk()
            ->assertJsonPath('data.status', 'connected');

        $this->getJson("/api/integrations/{$source->id}/preview?dimension=query&limit=10")
            ->assertOk()
            ->assertJsonPath('data.rows.0.query', 'used cars dubai')
            ->assertJsonPath('data.summary.clicks', 8)
            ->assertJsonPath('data.citation.source_name', 'Aboudcar Search Console');
    }

    public function test_updating_search_console_removes_legacy_generic_endpoint_settings(): void
    {
        $user = $this->integrationAdministrator();
        $source = DataSource::create([
            'name' => 'Aboudcar Search Console',
            'type' => 'google_search_console',
            'base_url' => 'https://www.googleapis.com/webmasters/v3',
            'status' => 'connected',
            'owner_id' => $user->id,
            'settings' => [
                'site_url' => 'https://www.aboudcar.com/',
                'health_path' => '/health',
                'data_path' => '/v1/reports/search-console',
            ],
        ]);
        $source->apiConfiguration()->create([
            'auth_type' => 'none',
            'timeout_seconds' => 15,
            'retry_count' => 0,
        ]);

        $this->actingAs($user)
            ->putJson("/api/integrations/{$source->id}", [
                'name' => 'Google Search Console',
                'type' => 'google_search_console',
                'description' => 'Read-only website search analytics.',
                'base_url' => 'https://www.googleapis.com/webmasters/v3',
                'auth_type' => 'none',
                'credentials' => [],
                'settings' => [
                    'site_url' => 'https://www.aboudcar.com',
                    'health_path' => '/health',
                    'data_path' => '/v1/reports/search-console',
                    'allowed_roles' => ['manager'],
                ],
                'timeout_seconds' => 15,
                'retry_count' => 0,
            ])
            ->assertOk()
            ->assertJsonMissingPath('data.settings.health_path')
            ->assertJsonMissingPath('data.settings.data_path')
            ->assertJsonPath('data.settings.site_url', 'https://www.aboudcar.com/');

        $settings = $source->fresh()->settings;
        $this->assertArrayNotHasKey('health_path', $settings);
        $this->assertArrayNotHasKey('data_path', $settings);
    }

    private function integrationAdministrator(): User
    {
        $permission = Permission::create([
            'name' => 'integrations.manage',
            'label' => 'Manage integrations',
            'group' => 'Administration',
        ]);
        $role = Role::create(['name' => 'administrator', 'label' => 'Administrator']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
