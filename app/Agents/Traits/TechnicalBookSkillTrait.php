<?php

namespace App\Agents\Traits;

use App\Services\TechnicalBookSearch;
use Illuminate\Support\Facades\Log;

/**
 * Skill partilhada por agentes que beneficiam da biblioteca técnica
 * PartYard (19 livros, 1.736 chunks: soldadura + arquitectura naval +
 * mecânica do navio + WPS/PQR + sistemas eléctricos).
 *
 * Activa-se ON-DEMAND quando o input do user contém palavras-chave
 * técnicas. Caso contrário não corre — não polui prompts comerciais
 * normais com contexto irrelevante.
 *
 * Agentes que usam este trait (2026-05-06):
 *   • WorkReportAgent (Eng. Repair) — sempre que possível
 *   • SalesAgent (Marco Sales) — para peças/manutenção marítima
 *   • SupportAgent (Marcus Support) — diagnóstico engine/system
 *   • VesselSearchAgent (Capitão Vasco) — naval repair, drydocks
 *   • EngineerAgent (Eng. Victor R&D) — specs técnicas, alternatives
 *
 * Pattern de uso (mirrors LogisticsSkillTrait):
 *
 *   public function chat(...) {
 *       $bookCtx = $this->augmentWithTechnicalBooks($message);
 *       $sysPrompt = $this->systemPrompt;
 *       if ($bookCtx) $sysPrompt .= "\n\n" . $bookCtx;
 *       // ...
 *   }
 */
trait TechnicalBookSkillTrait
{
    /**
     * Keywords técnicas que activam a pesquisa na biblioteca.
     * Lista intencionalmente larga — termos de soldadura, NDT,
     * engenharia naval, mecânica marinha, classificação societies.
     */
    protected array $technicalBookKeywords = [
        // Welding processes
        'welding','soldadura','soldagem','wps','pqr','aws','iso 15614','asme ix',
        'mma','smaw','tig','gtaw','mig','mag','gmaw','fcaw','saw','plasma',
        'preheat','pré-aquecimento','pre aquecimento','pwht','interpass',
        // Consumables
        'e6013','e7018','e7016','e308l','er70s','er316l','electrode','eléctrodo',
        // NDT / Inspection
        'ndt','utm','dft','ut','rt','mt','pt','vt','ultrasónico','ultrasonico',
        'magnetic particle','penetrant','radiographic','visual testing',
        // Naval engineering
        'naval','navio','vessel','casco','hull','convés','arquitectura naval',
        'estabilidade','displacement','deslocamento','plimsoll','load line',
        'estaleiro','shipyard','drydock','dique seco','reparação','repair',
        // Mechanical
        'bomba','pump','válvula','valve','rolamento','bearing','redutor','gearbox',
        'veio','shaft','propulsor','propeller','helice','hélice','impulsor',
        'engine','motor','mtu','caterpillar','wartsila','cummins','mak',
        // Class / IMO
        'imo','solas','marpol','dnv','lloyd','class society','classification',
        'iacs','bv','abs','rina','class survey','class notation',
        // Defects / Repair
        'crack','fissura','corrosion','corrosão','erosion','pitting',
        'plate replacement','doubler','fairing','overhaul','refit',
    ];

    /**
     * Devolve um bloco de contexto markdown com trechos relevantes
     * (ou string vazia se nada encontrado / sem keywords técnicos).
     *
     * @param  string|array  $message  Conteúdo do user (string ou array
     *   de Anthropic content blocks)
     * @param  int  $limit  Nº máximo de trechos a injectar (≤4 recomendado)
     * @param  string|null  $domain  'soldadura' | 'naval' | 'outros' | null
     */
    protected function augmentWithTechnicalBooks(
        string|array $message,
        int $limit = 3,
        ?string $domain = null
    ): string {
        try {
            $text = is_string($message)
                ? $message
                : implode(' ', array_map(
                    fn($b) => is_array($b) ? ($b['text'] ?? '') : (string) $b,
                    (array) $message
                ));

            // Skip se input vazio ou demasiado curto
            $text = trim($text);
            if (mb_strlen($text) < 8) return '';

            // Activar apenas se houver match de keyword técnico — evita
            // poluir prompts comerciais ("preço MTU 396") com contexto
            // de soldadura irrelevante.
            $lower = mb_strtolower($text);
            $hasTechnical = false;
            foreach ($this->technicalBookKeywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $hasTechnical = true;
                    break;
                }
            }
            if (!$hasTechnical) return '';

            return app(TechnicalBookSearch::class)->buildContextBlock($text, $limit, $domain);
        } catch (\Throwable $e) {
            Log::warning(static::class . ': technical book search failed: ' . $e->getMessage());
            return '';
        }
    }
}
