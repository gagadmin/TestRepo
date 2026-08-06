<?php

namespace App\Services\Integrations;

use App\Contracts\DataConnector;
use App\Data\ConnectionResult;
use App\Models\DataSource;

class GoogleSearchConsoleConnector implements DataConnector
{
    public function __construct(
        private readonly GoogleSearchConsoleService $searchConsole,
    ) {}

    public function testConnection(DataSource $dataSource): ConnectionResult
    {
        return $this->searchConsole->testConnection(
            data_get($dataSource->settings, 'site_url'),
        );
    }
}
