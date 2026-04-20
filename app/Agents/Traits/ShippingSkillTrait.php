<?php

namespace App\Agents\Traits;

use App\Services\ShippingRateService;

/**
 * ShippingSkillTrait — mix this into any agent that should be able to
 * answer "quanto custa enviar X kg daqui para Y?" accurately using the
 * PartYard UPS 2026 contract.
 *
 * The trait does two things:
 *   1. Appends a skill description to the agent's system prompt so the
 *      LLM knows it can reference this capability.
 *   2. Exposes a helper method {@see quoteShipping()} that agents can
 *      call before responding to compute a deterministic price.
 *
 * This is a LEGITIMATE PartYard business tool — the tariff data is under
 * contract Q9717213PT and belongs to HP-Group's internal operations.
 */
trait ShippingSkillTrait
{
    /**
     * Return the skill description to inject into the agent's system prompt.
     */
    protected function shippingSkillPromptBlock(): string
    {
        return ShippingRateService::skillPromptBlock();
    }

    /**
     * Convenience wrapper around ShippingRateService for agent code.
     *
     * Example:
     *   $q = $this->quoteShipping([
     *       'origin'      => 'PT',
     *       'destination' => 'BR',
     *       'weight_kg'   => 12.5,
     *   ]);
     *   if ($q['ok']) echo $q['price_excl_vat'];
     */
    protected function quoteShipping(array $opts): array
    {
        return (new ShippingRateService())->quote($opts);
    }

    protected function formatShippingQuote(array $quote): string
    {
        return (new ShippingRateService())->formatQuote($quote);
    }
}
