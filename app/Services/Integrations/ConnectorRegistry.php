<?php

namespace App\Services\Integrations;

use App\Contracts\DataConnector;
use InvalidArgumentException;

class ConnectorRegistry
{
    public function __construct(
        private readonly HttpApiConnector $httpConnector,
        private readonly GoogleSearchConsoleConnector $searchConsoleConnector,
    ) {}

    public function for(string $type): DataConnector
    {
        if (! array_key_exists($type, config('integrations.types'))) {
            throw new InvalidArgumentException("Unsupported integration type [{$type}].");
        }

        return $type === 'google_search_console'
            ? $this->searchConsoleConnector
            : $this->httpConnector;
    }
}
