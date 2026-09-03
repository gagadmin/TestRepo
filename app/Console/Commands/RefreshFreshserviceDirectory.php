<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Services\Integrations\IntegrationRequestFactory;
use App\Services\Integrations\IntegrationUrlGuard;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RefreshFreshserviceDirectory extends Command
{
    protected $signature = 'freshservice:refresh-directory
                            {--source-id= : Refresh a specific data source}
                            {--force : Skip confirmation}';

    protected $description = 'Refresh Freshservice agent and group directory cache from API';

    public function handle(IntegrationUrlGuard $urlGuard, IntegrationRequestFactory $requests): int
    {
        $query = DataSource::where('type', 'freshservice')
            ->where('status', 'connected');

        if ($sourceId = $this->option('source-id')) {
            $query->whereKey($sourceId);
        }

        $sources = $query->with('apiConfiguration')->get();

        if ($sources->isEmpty()) {
            $this->info('No Freshservice sources found.');

            return self::SUCCESS;
        }

        $this->info("Refreshing directory for {$sources->count()} Freshservice source(s)...");

        $successCount = 0;
        $failureCount = 0;

        foreach ($sources as $source) {
            if (! $this->refreshSource($source, $urlGuard, $requests)) {
                $failureCount++;

                continue;
            }
            $successCount++;
        }

        $this->info("✓ Refreshed: {$successCount}, ✗ Failed: {$failureCount}");

        return $failureCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function refreshSource(
        DataSource $source,
        IntegrationUrlGuard $urlGuard,
        IntegrationRequestFactory $requests,
    ): bool {
        try {
            $baseUrl = rtrim((string) $source->base_url, '/');
            $urlGuard->assertAllowed($baseUrl);

            $this->line("  Refreshing: {$source->name}");

            // Fetch agents
            $agents = $this->fetchPaginated(
                $source,
                "{$baseUrl}/api/v2/agents",
                'agents',
                $urlGuard,
                $requests,
            );
            $this->cacheBatch($source->id, 'agent', $agents);
            $this->line("    → Cached {$agents->count()} agents");

            // Fetch groups
            $groups = $this->fetchPaginated(
                $source,
                "{$baseUrl}/api/v2/groups",
                'groups',
                $urlGuard,
                $requests,
            );
            $this->cacheBatch($source->id, 'group', $groups);
            $this->line("    → Cached {$groups->count()} groups");

            return true;
        } catch (RuntimeException $e) {
            $this->error("    ✗ Error: {$e->getMessage()}");

            return false;
        } catch (\Exception $e) {
            $this->error("    ✗ Unexpected error: {$e->getMessage()}");

            return false;
        }
    }

    private function fetchPaginated(
        DataSource $source,
        string $url,
        string $key,
        IntegrationUrlGuard $urlGuard,
        IntegrationRequestFactory $requests,
    ) {
        $records = collect();
        $perPage = 100;
        $maxPages = config('integrations.freshservice.max_directory_pages', 50);

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $urlGuard->assertAllowed($url);
                $response = $requests->make($source->apiConfiguration)->get($url, [
                    'page' => $page,
                    'per_page' => $perPage,
                ]);

                if (! $response->successful()) {
                    throw new RuntimeException(match ($response->status()) {
                        401, 403 => 'Invalid Freshservice credentials',
                        429 => 'Rate limited by Freshservice',
                        default => "HTTP {$response->status()}",
                    });
                }

                $pageRecords = $response->json($key, []);

                if (! is_array($pageRecords)) {
                    throw new RuntimeException('Unexpected response format from Freshservice');
                }

                if (empty($pageRecords)) {
                    break;
                }

                $records->push(...array_filter($pageRecords, 'is_array'));

                if (count($pageRecords) < $perPage) {
                    break;
                }
            } catch (\Exception $e) {
                throw new RuntimeException("Failed to fetch page {$page}: {$e->getMessage()}");
            }
        }

        return $records;
    }

    private function cacheBatch(int $sourceId, string $entityType, $records): void
    {
        $batchSize = 100;
        $batches = $records->chunk($batchSize);

        foreach ($batches as $batch) {
            $inserts = $batch->map(function (array $record) use ($sourceId, $entityType) {
                $id = $record['id'] ?? null;
                $name = $record['name']
                    ?? trim(($record['first_name'] ?? '').' '.($record['last_name'] ?? ''))
                    ?: ($record['contact']['name'] ?? null);

                return $id && filled($name) ? [
                    'data_source_id' => $sourceId,
                    'entity_type' => $entityType,
                    'entity_id' => (int) $id,
                    'name' => $name,
                    'data' => json_encode($record),
                    'refreshed_at' => now(),
                ] : null;
            })->filter()->values();

            if ($inserts->isEmpty()) {
                continue;
            }

            try {
                DB::table('freshservice_directory_cache')
                    ->upsert(
                        $inserts->toArray(),
                        ['data_source_id', 'entity_type', 'entity_id'],
                        ['name', 'data', 'refreshed_at'],
                    );
            } catch (QueryException $e) {
                throw new RuntimeException("Failed to cache {$entityType} records: {$e->getMessage()}");
            }
        }
    }
}
