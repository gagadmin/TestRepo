<?php

namespace App\Services\Ai\Tools;

use App\Contracts\AiTool;
use App\Data\ToolResult;
use App\Models\AiToolFailure;
use App\Models\DataSource;
use App\Models\User;
use App\Services\Ai\ReportingDataGateway;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class ConfiguredReportingTool implements AiTool
{
    public function __construct(
        private readonly string $toolName,
        private readonly string $description,
        private readonly array $sourceTypes,
        private readonly string $handler,
        private readonly ReportingDataGateway $gateway,
        private readonly array $options = [],
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'name' => $this->toolName,
            'description' => $this->description,
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'data_source_id' => [
                        'type' => ['integer', 'null'],
                        'description' => 'Connected data source ID. Use null only when exactly one eligible source exists.',
                    ],
                    'date_from' => [
                        'type' => ['string', 'null'],
                        'description' => 'Start date in YYYY-MM-DD format, or null. For "today" use the same value for '
                            .'date_from and date_to, in the reporting timezone stated in your instructions.',
                    ],
                    'date_to' => [
                        'type' => ['string', 'null'],
                        'description' => 'End date in YYYY-MM-DD format, or null. Inclusive.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 200,
                        'description' => 'Maximum records requested from the approved endpoint.',
                    ],
                ],
                'required' => ['data_source_id', 'date_from', 'date_to', 'limit'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function execute(User $user, array $arguments): ToolResult
    {
        if (! $user->hasPermission('reports.view')) {
            throw new RuntimeException('The user is not authorized to retrieve report data.');
        }

        $validated = Validator::make($arguments, [
            'data_source_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'limit' => ['required', 'integer', 'between:1,200'],
        ])->validate();

        $source = $this->resolveSource($user, $validated['data_source_id'] ?? null);

        try {
            return $this->gateway->fetch($source, [
                'report_type' => $this->toolName,
                'handler' => $this->handler,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'limit' => $validated['limit'],
                'options' => $this->options,
            ], $user);
        } catch (Throwable $exception) {
            // Record why the connector failed so the assistant can report the
            // real reason instead of concluding the capability does not exist,
            // and so administrators get a list of connectors failing in practice.
            $this->recordFailure(
                AiToolFailure::REASON_UPSTREAM_ERROR,
                $exception->getMessage(),
                $source,
            );

            throw $exception;
        }
    }

    /**
     * Pick the single data source this call should read.
     */
    private function resolveSource(User $user, ?int $requestedId): DataSource
    {
        $query = DataSource::query()
            ->with('apiConfiguration')
            ->where('status', 'connected')
            ->whereIn('type', $this->sourceTypes);

        if ($requestedId) {
            $query->whereKey($requestedId);
        }

        $sources = $query->get()->filter(fn (DataSource $source) => $source->isAccessibleBy($user));

        if ($sources->isEmpty()) {
            // Distinguish "nothing is connected" from "you cannot see it".
            // The old message conflated the two, which is what made a
            // permissions problem look like a missing connector.
            $anyConnected = DataSource::query()
                ->where('status', 'connected')
                ->whereIn('type', $this->sourceTypes)
                ->exists();

            if ($anyConnected) {
                $this->recordFailure(
                    AiToolFailure::REASON_NOT_AUTHORIZED,
                    'A connected source exists but is not visible to this user.',
                );

                throw new RuntimeException(
                    'A connected source exists for this report, but your account is not authorized to read it. '
                    .'Ask an administrator to grant access to the relevant data source.'
                );
            }

            $types = implode(', ', $this->sourceTypes);
            $this->recordFailure(
                AiToolFailure::REASON_NO_SOURCE,
                "No connected data source of type: {$types}.",
            );

            throw new RuntimeException(
                "No connected data source of type [{$types}] is available for this report."
            );
        }

        if (! $requestedId && $sources->count() > 1) {
            throw new RuntimeException(
                'Multiple eligible sources exist. Specify a data_source_id. Available: '
                .$sources->map(fn (DataSource $source) => "{$source->id} ({$source->name})")->implode(', ')
            );
        }

        return $sources->first();
    }

    private function recordFailure(string $reason, string $message, ?DataSource $source = null): void
    {
        $fingerprint = "{$this->toolName}:{$reason}:".($source->id ?? 'none');
        $now = now();

        $failure = AiToolFailure::firstOrNew(['fingerprint' => $fingerprint]);

        $failure->fill([
            'tool_name' => $this->toolName,
            'data_source_id' => $source?->id,
            'reason' => $reason,
            'message' => str($message)->limit(500)->toString(),
            'occurrences' => ($failure->occurrences ?? 0) + 1,
            'first_failed_at' => $failure->first_failed_at ?? $now,
            'last_failed_at' => $now,
            // A recurrence reopens a previously resolved failure.
            'resolved' => false,
        ])->save();
    }
}
