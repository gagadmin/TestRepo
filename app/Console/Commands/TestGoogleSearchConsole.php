<?php

namespace App\Console\Commands;

use App\Services\Integrations\GoogleSearchConsoleService;
use Illuminate\Console\Command;

class TestGoogleSearchConsole extends Command
{
    protected $signature = 'search-console:test';

    protected $description = 'Test read-only Google Search Console authentication and property access';

    public function handle(GoogleSearchConsoleService $searchConsole): int
    {
        $result = $searchConsole->testConnection();

        if (! $result->successful) {
            $this->error($result->message);
            $this->line('Error code: '.($result->errorCode ?? 'unknown'));

            if ($result->httpStatus !== null) {
                $this->line('HTTP status: '.$result->httpStatus);
            }

            if (isset($result->context['google_status'])) {
                $this->line('Google status: '.$result->context['google_status']);
            }

            if (isset($result->context['google_reason'])) {
                $this->line('Google reason: '.$result->context['google_reason']);
            }

            return self::FAILURE;
        }

        $this->info($result->message);
        $this->table(
            ['Property', 'Permission', 'Accessible properties', 'Duration'],
            [[
                $result->context['site_url'] ?? 'configured',
                $result->context['permission_level'] ?? 'unknown',
                $result->context['accessible_site_count'] ?? 0,
                $result->durationMs.' ms',
            ]],
        );

        return self::SUCCESS;
    }
}
