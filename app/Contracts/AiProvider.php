<?php

namespace App\Contracts;

interface AiProvider
{
    public function name(): string;

    public function configured(): bool;

    public function respond(array $payload): array;
}
