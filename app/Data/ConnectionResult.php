<?php

namespace App\Data;

class ConnectionResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
        public readonly int $durationMs = 0,
        public readonly array $context = [],
    ) {}
}
