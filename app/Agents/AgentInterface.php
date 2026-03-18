<?php

namespace App\Agents;

interface AgentInterface
{
    public function chat(string $message, array $history = []): string;
    public function getName(): string;
    public function getModel(): string;
}
