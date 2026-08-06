<?php

namespace App\Contracts;

use App\Data\ToolResult;
use App\Models\User;

interface AiTool
{
    public function name(): string;

    public function definition(): array;

    public function execute(User $user, array $arguments): ToolResult;
}
