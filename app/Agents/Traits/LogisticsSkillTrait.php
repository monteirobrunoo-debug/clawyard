<?php

namespace App\Agents\Traits;

use App\Services\LogisticsKnowledgeService;

/**
 * LogisticsSkillTrait — gives EVERY PartYard agent a baseline of
 * logistics/supply-chain knowledge (vocabulary, modals, processes,
 * metrics and IT systems) extracted from "Introdução à Logística"
 * (Editora GEN/Atlas, 2024).
 *
 * This is the counterpart of ShippingSkillTrait:
 *   - ShippingSkillTrait answers "how much?"  (UPS pricing)
 *   - LogisticsSkillTrait  answers "what is / how / which modal?"
 *
 * Usage (inside an agent constructor):
 *
 *   $this->systemPrompt .= $this->logisticsSkillPromptBlock();
 *
 * Lightweight — only adds ~3.5KB to the system prompt. Safe to mix
 * into every agent.
 */
trait LogisticsSkillTrait
{
    /**
     * Return the skill description to inject into the agent's system
     * prompt. Call once, typically right after the persona/specialty
     * definition.
     */
    protected function logisticsSkillPromptBlock(): string
    {
        return LogisticsKnowledgeService::skillPromptBlock();
    }

    /**
     * Look up a logistics term in the 292-entry glossary.
     * Returns the definition or null.
     */
    protected function lookupLogisticsTerm(string $term): ?string
    {
        return LogisticsKnowledgeService::lookup($term);
    }

    /**
     * Return the structured modal reference (for modal recommendation logic).
     */
    protected function transportModes(): array
    {
        return LogisticsKnowledgeService::transportModes();
    }
}
