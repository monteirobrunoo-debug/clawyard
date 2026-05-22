<?php

namespace App\Agents\Traits;

use App\Services\AgentTools\NsnLookupTool;
use Illuminate\Support\Facades\Log;

/**
 * Pre-call NSN augmentation for chat-only agents.
 *
 * Os agentes em chat (EngineerAgent, MilDefAgent, CapitaoAgent, SalesAgent)
 * não usam o tool-use loop do AutonomousAgentRunner — usam o padrão
 * augmentWith* para enriquecer o prompt antes de chamar a Anthropic API.
 *
 * Este trait detecta NSNs (NATO Stock Numbers) na mensagem do utilizador
 * e injecta, antes da call, o resultado do NsnLookupTool (descrição,
 * OEM, distribuidores, emails). Usa cache 7d, portanto re-perguntas
 * sobre o mesmo NSN são grátis.
 *
 * Formatos aceites:
 *   • 5331-01-234-5678   (canónico, com hifens)
 *   • 5331012345678      (13 dígitos contíguos)
 *
 * Limite de 3 NSNs por mensagem para não explodir custo/latência —
 * se o user pedir um lote grande, o agente pode ainda chamar a CLI
 * artisan nsn:lookup ou pedir ao Eng. Victor por batch.
 *
 * DEPENDENCY: requer que a classe também use WebSearchTrait — usa o
 * helper appendToMessage() para preservar mensagens multimodais
 * (string OU array com blocos text/document/image).
 */
trait NsnLookupTrait
{
    /** NSN canónico: 4-2-3-4 dígitos com hifens. */
    private const NSN_RE_HYPHEN = '/\b(\d{4})-(\d{2})-(\d{3})-(\d{4})\b/';

    /** NSN compacto: 13 dígitos contíguos (precedido/seguido de não-dígito). */
    private const NSN_RE_PLAIN = '/(?<!\d)(\d{13})(?!\d)/';

    /** Máximo de NSNs a expandir num único turn (custo/latência). */
    private const MAX_NSNS_PER_MESSAGE = 3;

    /**
     * Extrai NSNs (normalizados para XXXX-XX-XXX-XXXX) da mensagem.
     * Aceita string OU multimodal array.
     *
     * @return array<int,string> NSNs únicos, max MAX_NSNS_PER_MESSAGE.
     */
    protected function extractNsns(string|array $message): array
    {
        $text = is_array($message)
            ? implode(' ', array_map(fn ($b) => $b['text'] ?? '', $message))
            : $message;

        $found = [];

        if (preg_match_all(self::NSN_RE_HYPHEN, $text, $m)) {
            foreach ($m[0] as $hit) {
                $found[] = $hit;
            }
        }

        if (preg_match_all(self::NSN_RE_PLAIN, $text, $m)) {
            foreach ($m[1] as $digits) {
                // Formata para canónico
                $found[] = substr($digits, 0, 4) . '-'
                         . substr($digits, 4, 2) . '-'
                         . substr($digits, 6, 3) . '-'
                         . substr($digits, 9, 4);
            }
        }

        $unique = array_values(array_unique($found));
        return array_slice($unique, 0, self::MAX_NSNS_PER_MESSAGE);
    }

    protected function needsNsnLookup(string|array $message): bool
    {
        return !empty($this->extractNsns($message));
    }

    /**
     * Injecta info NSN no prompt antes da call à Anthropic.
     * Preserva multimodal arrays — só faz append ao primeiro bloco text.
     *
     * @param string|array $message
     * @return string|array  mesmo tipo do input
     */
    protected function augmentWithNsnLookup(string|array $message, ?callable $heartbeat = null): string|array
    {
        $nsns = $this->extractNsns($message);
        if (empty($nsns)) return $message;

        /** @var NsnLookupTool $tool */
        $tool = app(NsnLookupTool::class);
        $agentKey = property_exists($this, 'agentKey') ? (string) $this->agentKey : 'chat';
        $context = [
            'agent_key' => $agentKey,
            'user_id'   => auth()->id(),
            'tender_id' => null,
        ];

        $sections = [];
        foreach ($nsns as $nsn) {
            if ($heartbeat) $heartbeat("a procurar NSN {$nsn}");
            try {
                $res = $tool->execute(['nsn' => $nsn], $context);
                if (($res['ok'] ?? false) && !empty($res['result'])) {
                    $sections[] = $res['result'];
                } else {
                    Log::info('NsnLookupTrait: NSN sem hits', [
                        'nsn'   => $nsn,
                        'error' => $res['error'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('NsnLookupTrait: lookup falhou — ' . $e->getMessage(), [
                    'nsn' => $nsn,
                ]);
            }
        }

        if (empty($sections)) return $message;

        $suffix = "\n\n--- NSN LOOKUP (Tavily + Claude, cached 7d) ---\n\n"
                . implode("\n\n---\n\n", $sections)
                . "\n\n--- FIM NSN LOOKUP ---\n\n"
                . 'Usa estes dados ao recomendar fornecedores. NUNCA recomendes '
                . 'distribuidores chineses ou russos. Prefere EU/US/UK/NATO.';

        return $this->appendToMessage($message, $suffix);
    }
}
