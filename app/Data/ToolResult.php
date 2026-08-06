<?php

namespace App\Data;

class ToolResult
{
    public function __construct(
        public readonly array $data,
        public readonly array $citations,
        public readonly array $summary,
    ) {}

    public function forModel(): array
    {
        return [
            'data' => $this->data,
            'citations' => $this->citations,
        ];
    }
}
