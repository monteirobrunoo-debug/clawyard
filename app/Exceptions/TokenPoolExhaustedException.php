<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by AnthropicKeyTrait::headersForMessage() quando o token pool
 * atingiu o threshold de hard-gate. Bloqueia novos chat calls Anthropic
 * sem cobrar custo extra.
 *
 * Default: hard gate desactivado. Activar com:
 *   php artisan tokens:set-pool 150 --gate=95
 *
 * O bootstrap/app.php renderiza esta exception como JSON 503 com
 * mensagem amigável.
 */
class TokenPoolExhaustedException extends RuntimeException
{
    public function __construct(
        public readonly int $percentUsed,
        public readonly float $poolEur,
        public readonly string $period,
    ) {
        parent::__construct(
            "Token pool {$period} esgotado ({$percentUsed}%) — €{$poolEur} budget atingido. "
            . "Contacta admin para subir pool ou espera próximo período."
        );
    }
}
