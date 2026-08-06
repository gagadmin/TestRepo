<?php

namespace App\Exceptions;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $providerCode,
        public readonly int $upstreamStatus,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public function clientStatus(): int
    {
        return match ($this->providerCode) {
            'rate_limit_exceeded' => 429,
            'insufficient_quota', 'authentication_error', 'permission_denied' => 503,
            default => 502,
        };
    }
}
