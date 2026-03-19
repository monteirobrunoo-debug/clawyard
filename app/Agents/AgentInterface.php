<?php

namespace App\Agents;

interface AgentInterface
{
    public function chat(string $message, array $history = []): string;
    public function stream(string $message, array $history, callable $onChunk, ?callable $heartbeat = null): string;
    public function getName(): string;
    public function getModel(): string;
}
