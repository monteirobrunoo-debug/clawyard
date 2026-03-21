<?php

namespace App\Agents;

interface AgentInterface
{
    public function chat(string|array $message, array $history = []): string;
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string;
    public function getName(): string;
    public function getModel(): string;
}
