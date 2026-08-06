<?php

namespace App\Http\Controllers;

use App\Http\Requests\DataSourceRequest;
use App\Http\Requests\SearchConsolePreviewRequest;
use App\Models\DataSource;
use App\Models\Report;
use App\Services\Integrations\GoogleSearchConsoleService;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IntegrationController extends Controller
{
    public function index(): JsonResponse
    {
        $sources = DataSource::query()
            ->with(['apiConfiguration', 'owner:id,name', 'latestRun'])
            ->latest()
            ->get()
            ->map(fn (DataSource $source) => $this->serialize($source));

        return response()->json([
            'data' => $sources,
            'types' => collect(config('integrations.types'))
                ->map(fn (array $definition, string $value) => [
                    'value' => $value,
                    ...$definition,
                ])
                ->values(),
            'auth_types' => [
                ['value' => 'none', 'label' => 'No authentication'],
                ['value' => 'bearer', 'label' => 'Bearer token'],
                ['value' => 'api_key', 'label' => 'API key'],
                ['value' => 'basic', 'label' => 'Basic authentication'],
            ],
            'search_console' => [
                'site_url' => config('services.search_console.site_url'),
                'api_url' => 'https://www.googleapis.com/webmasters/v3',
                'configured' => filled(config('services.search_console.site_url'))
                    && filled(config('services.search_console.credentials')),
            ],
        ]);
    }

    public function store(DataSourceRequest $request): JsonResponse
    {
        $source = DB::transaction(function () use ($request) {
            $source = DataSource::create([
                ...$request->safe()->only(['name', 'type', 'description', 'base_url', 'settings']),
                'owner_id' => $request->user()->id,
                'status' => 'draft',
            ]);

            $source->apiConfiguration()->create([
                'auth_type' => $request->string('auth_type')->toString(),
                'encrypted_credentials' => $request->input('credentials', []),
                'encrypted_headers' => $request->input('headers', []),
                'timeout_seconds' => $request->integer('timeout_seconds'),
                'retry_count' => $request->integer('retry_count'),
            ]);

            return $source;
        });

        return response()->json([
            'data' => $this->serialize($source->load(['apiConfiguration', 'owner', 'latestRun'])),
        ], 201);
    }

    public function update(DataSourceRequest $request, DataSource $dataSource): JsonResponse
    {
        DB::transaction(function () use ($request, $dataSource) {
            $dataSource->update($request->safe()->only([
                'name', 'type', 'description', 'base_url', 'settings',
            ]));

            $configuration = $dataSource->apiConfiguration()->firstOrNew();
            $configuration->fill([
                'auth_type' => $request->string('auth_type')->toString(),
                'timeout_seconds' => $request->integer('timeout_seconds'),
                'retry_count' => $request->integer('retry_count'),
            ]);

            if ($request->has('credentials')) {
                $configuration->encrypted_credentials = $request->input('credentials') ?? [];
            } elseif ($request->string('auth_type')->toString() === 'none') {
                $configuration->encrypted_credentials = [];
            }

            if ($request->has('headers')) {
                $configuration->encrypted_headers = $request->input('headers') ?? [];
            }

            $configuration->save();
        });

        return response()->json([
            'data' => $this->serialize($dataSource->fresh(['apiConfiguration', 'owner', 'latestRun'])),
        ]);
    }

    public function test(
        Request $request,
        DataSource $dataSource,
        IntegrationManager $manager
    ): JsonResponse {
        $result = $manager->testConnection($dataSource, $request->user());

        return response()->json([
            'result' => [
                'successful' => $result->successful,
                'message' => $result->message,
                'http_status' => $result->httpStatus,
                'error_code' => $result->errorCode,
                'duration_ms' => $result->durationMs,
            ],
            'data' => $this->serialize($dataSource->fresh(['apiConfiguration', 'owner', 'latestRun'])),
        ], $result->successful ? 200 : 422);
    }

    public function testSearchConsole(GoogleSearchConsoleService $searchConsole): JsonResponse
    {
        $result = $searchConsole->testConnection();

        return response()->json([
            'result' => [
                'successful' => $result->successful,
                'message' => $result->message,
                'http_status' => $result->httpStatus,
                'error_code' => $result->errorCode,
                'duration_ms' => $result->durationMs,
                'context' => array_intersect_key($result->context, array_flip([
                    'site_url',
                    'accessible_site_count',
                    'permission_level',
                    'google_status',
                    'google_reason',
                ])),
            ],
        ], $result->successful ? 200 : 422);
    }

    public function preview(
        SearchConsolePreviewRequest $request,
        DataSource $dataSource,
        GoogleSearchConsoleService $searchConsole,
    ): JsonResponse {
        abort_unless($dataSource->type === 'google_search_console', 404);
        abort_unless($dataSource->isAccessibleBy($request->user()), 403);
        abort_unless($dataSource->status === 'connected', 409, 'Test this data source successfully before previewing data.');

        try {
            $result = $searchConsole->analytics(
                $request->validated(),
                data_get($dataSource->settings, 'site_url'),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'data' => [
                ...$result,
                'citation' => [
                    'source_id' => $dataSource->id,
                    'source_name' => $dataSource->name,
                    'source_type' => $dataSource->type,
                    'retrieved_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    public function destroy(DataSource $dataSource): JsonResponse
    {
        $isAssigned = Report::query()
            ->where('definition->source_id', $dataSource->id)
            ->exists();

        if ($isAssigned) {
            return response()->json([
                'message' => 'This data source is assigned to one or more reports and cannot be removed.',
            ], 409);
        }

        $dataSource->delete();

        return response()->json(['message' => 'Data source removed.']);
    }

    private function serialize(DataSource $source): array
    {
        $configuration = $source->apiConfiguration;
        $latestRun = $source->latestRun;

        return [
            'id' => $source->id,
            'name' => $source->name,
            'type' => $source->type,
            'type_label' => config("integrations.types.{$source->type}.label", $source->type),
            'type_icon' => config("integrations.types.{$source->type}.icon", 'pi-database'),
            'description' => $source->description,
            'base_url' => $source->base_url,
            'status' => $source->status,
            'settings' => $source->settings ?? [],
            'owner' => $source->owner?->only(['id', 'name']),
            'auth_type' => $configuration?->auth_type ?? 'none',
            'has_credentials' => ! empty($configuration?->encrypted_credentials),
            'has_headers' => ! empty($configuration?->encrypted_headers),
            'timeout_seconds' => $configuration?->timeout_seconds ?? 30,
            'retry_count' => $configuration?->retry_count ?? 2,
            'last_tested_at' => $source->last_tested_at,
            'latest_run' => $latestRun ? [
                'status' => $latestRun->status,
                'http_status' => $latestRun->http_status,
                'duration_ms' => $latestRun->duration_ms,
                'message' => $latestRun->message,
                'finished_at' => $latestRun->finished_at,
            ] : null,
        ];
    }
}
