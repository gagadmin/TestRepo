<?php

namespace Tests\Feature;

use App\Services\Integrations\GoogleSearchConsoleService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleSearchConsoleTest extends TestCase
{
    private string $credentialPath;

    protected function setUp(): void
    {
        parent::setUp();

        $key = @openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
            $this->fail('Unable to generate a temporary service-account key.');
        }

        $path = tempnam(sys_get_temp_dir(), 'gsc-');

        if ($path === false) {
            $this->fail('Unable to create a temporary credential file.');
        }

        $this->credentialPath = $path;
        file_put_contents($this->credentialPath, json_encode([
            'type' => 'service_account',
            'client_email' => 'search-console-test@example.test',
            'private_key' => $privateKey,
        ], JSON_THROW_ON_ERROR));

        config()->set([
            'services.search_console.site_url' => 'https://www.example.com/',
            'services.search_console.credentials' => $this->credentialPath,
            'services.search_console.timeout_seconds' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->credentialPath) && is_file($this->credentialPath)) {
            unlink($this->credentialPath);
        }

        parent::tearDown();
    }

    public function test_command_authenticates_and_confirms_configured_property_access(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    [
                        'siteUrl' => 'https://www.example.com/',
                        'permissionLevel' => 'siteFullUser',
                    ],
                ],
            ]),
        ]);

        $this->artisan('search-console:test')
            ->expectsOutputToContain('connection established')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.googleapis.com/webmasters/v3/sites'
            && $request->hasHeader('Authorization', 'Bearer test-access-token'));
    }

    public function test_command_fails_when_service_account_cannot_access_configured_property(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    [
                        'siteUrl' => 'sc-domain:example.org',
                        'permissionLevel' => 'siteRestrictedUser',
                    ],
                ],
            ]),
        ]);

        $this->artisan('search-console:test')
            ->expectsOutputToContain('not accessible')
            ->expectsOutput('Error code: site_not_accessible')
            ->assertFailed();
    }

    public function test_command_reports_missing_configuration_without_making_requests(): void
    {
        config()->set('services.search_console.site_url', null);
        Http::fake();

        $this->artisan('search-console:test')
            ->expectsOutput('GOOGLE_SEARCH_CONSOLE_SITE_URL is not configured.')
            ->expectsOutput('Error code: configuration_error')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_command_reports_safe_google_error_details(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'error' => [
                    'code' => 403,
                    'status' => 'PERMISSION_DENIED',
                    'errors' => [
                        ['reason' => 'accessNotConfigured'],
                    ],
                ],
            ], 403),
        ]);

        $this->artisan('search-console:test')
            ->expectsOutput('Error code: api_error')
            ->expectsOutput('HTTP status: 403')
            ->expectsOutput('Google status: PERMISSION_DENIED')
            ->expectsOutput('Google reason: accessNotConfigured')
            ->assertFailed();
    }

    public function test_search_analytics_returns_normalized_metrics_and_uses_read_only_google_endpoint(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
                ->push([
                    'rows' => [[
                        'clicks' => 25,
                        'impressions' => 500,
                        'ctr' => 0.05,
                        'position' => 4.125,
                    ]],
                ])
                ->push([
                    'rows' => [[
                        'keys' => ['electric cars'],
                        'clicks' => 10,
                        'impressions' => 200,
                        'ctr' => 0.05,
                        'position' => 3.456,
                    ]],
                ]),
        ]);

        $result = app(GoogleSearchConsoleService::class)->analytics([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-27',
            'dimension' => 'query',
            'limit' => 25,
        ]);

        $this->assertSame('electric cars', $result['rows'][0]['query']);
        $this->assertSame(5.0, $result['rows'][0]['ctr']);
        $this->assertSame(3.46, $result['rows'][0]['position']);
        $this->assertSame(25, $result['summary']['clicks']);
        $this->assertSame(5.0, $result['summary']['ctr']);

        Http::assertSent(fn ($request): bool => str_contains(
            $request->url(),
            '/sites/https%3A%2F%2Fwww.example.com%2F/searchAnalytics/query'
        ) && $request->hasHeader('Authorization', 'Bearer test-access-token'));
        Http::assertSent(fn ($request): bool => ($request->data()['dimensions'] ?? null) === ['query']
            && ($request->data()['rowLimit'] ?? null) === 25);
    }
}
