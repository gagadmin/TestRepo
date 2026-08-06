<?php

namespace App\Services\Integrations;

use App\Data\ConnectionResult;
use App\Models\DataSource;
use App\Models\IntegrationRun;
use App\Models\User;

class IntegrationManager
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
    ) {}

    public function testConnection(DataSource $dataSource, User $initiator): ConnectionResult
    {
        $run = IntegrationRun::create([
            'data_source_id' => $dataSource->id,
            'initiated_by' => $initiator->id,
            'operation' => 'connection_test',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $result = $this->connectors
            ->for($dataSource->type)
            ->testConnection($dataSource->loadMissing('apiConfiguration'));

        $run->update([
            'status' => $result->successful ? 'succeeded' : 'failed',
            'http_status' => $result->httpStatus,
            'duration_ms' => $result->durationMs,
            'error_code' => $result->errorCode,
            'message' => $result->message,
            'context' => $result->context,
            'finished_at' => now(),
        ]);

        $dataSource->update([
            'status' => $result->successful ? 'connected' : 'error',
            'last_tested_at' => now(),
        ]);

        return $result;
    }
}
