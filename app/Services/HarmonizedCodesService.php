<?php

namespace App\Services;

use App\Data\HarmonizedCodesReference as Ref;

/**
 * HarmonizedCodesService — pauta aduaneira EU (HS / CN / TARIC).
 *
 * Serve DUAS direcções:
 *   A) O utilizador fornece um CÓDIGO (2/4/6/8/10 dígitos) → devolvemos
 *      o significado estruturado + link TARIC para consulta oficial.
 *   B) O utilizador DESCREVE uma mercadoria → o LLM, munido deste bloco
 *      de conhecimento, propõe o capítulo + posição + candidatos de CN
 *      e remete para TARIC para confirmação.
 *
 * Este serviço fornece apenas conhecimento estruturado e URLs. A
 * classificação vinculativa só pode ser emitida pela AT (Informação
 * Pautal Vinculativa — IPV / BTI) ou por despachante oficial.
 */
final class HarmonizedCodesService
{
    /**
     * Structured prompt block to inject into an agent's system prompt.
     * Compact but complete — covers code structure, all 21 sections,
     * all 99 chapters, the PartYard-critical deep-dive and live sources.
     */
    public static function promptBlock(): string
    {
        $out  = "\n\n=== PAUTA ADUANEIRA EUROPEIA — HS / CN / TARIC ===\n\n";

        $out .= "ESTRUTURA DO CÓDIGO (do mais genérico ao mais específico):\n";
        $out .= " · 2 dígitos  = Capítulo (HS — padrão OMA/WCO)\n";
        $out .= " · 4 dígitos  = Posição\n";
        $out .= " · 6 dígitos  = Subposição HS (padrão global, ~200 países)\n";
        $out .= " · 8 dígitos  = CN — Nomenclatura Combinada UE (Reg. 2658/87)\n";
        $out .= " · 10 dígitos = TARIC — medidas específicas UE (antidumping, quotas, …)\n";
        $out .= " · 11º/12º díg = extensão nacional PT (IVA, ICE, regimes especiais)\n\n";

        $out .= "21 SECÇÕES DO SISTEMA HARMONIZADO:\n";
        foreach (Ref::SECTIONS as $roman => $s) {
            $out .= sprintf(" · Secção %-5s (Cap. %-6s): %s\n", $roman, $s['chapters'], $s['title']);
        }

        $out .= "\nOS 99 CAPÍTULOS (2 dígitos):\n";
        foreach (Ref::CHAPTERS as $code => $title) {
            $out .= " · {$code} — {$title}\n";
        }

        $out .= "\n\n═══ DRILL-DOWN: CAPÍTULOS CRÍTICOS PARA PARTYARD ═══\n";
        $out .= "(peças industriais, aeronáutica, náutica, defesa, instrumentos)\n\n";
        foreach (Ref::PARTYARD_KEY_HEADINGS as $chapter => $data) {
            $out .= "▶ Cap. {$chapter} — {$data['label']}:\n";
            foreach ($data['headings'] as $h => $desc) {
                $out .= "   {$h}  {$desc}\n";
            }
            $out .= "\n";
        }

        $out .= "MEDIDAS TRANSPORTADAS PELO TARIC (aplicam-se além do direito base):\n";
        foreach (Ref::TARIC_MEASURES as $m) {
            $out .= " · {$m}\n";
        }

        $out .= "\nFONTES OFICIAIS E LIVE LOOKUP:\n";
        $out .= " · Dataset EU (data.europa.eu):  " . Ref::LIVE_SOURCES['dataset']       . "\n";
        $out .= " · TARIC Consultation online:   "  . Ref::LIVE_SOURCES['consultation']  . "\n";
        $out .= " · Bulk mensal (CIRCABC):       "  . Ref::LIVE_SOURCES['bulk_circabc']  . "\n";
        $out .= " · VIES (validar NIF UE):       "  . Ref::LIVE_SOURCES['vies']          . "\n";
        $out .= " · WCO HS 2022:                 "  . Ref::LIVE_SOURCES['wco']           . "\n";
        $out .= " · AT Portugal (info aduaneira):"  . Ref::LIVE_SOURCES['at_portugal']   . "\n";

        $out .= "\n═══ COMO RESPONDER ═══\n\n";

        $out .= "CASO A — O UTILIZADOR FORNECE UM CÓDIGO:\n";
        $out .= " 1. Normaliza (remove pontos/espaços) e identifica o nível (2/4/6/8/10).\n";
        $out .= " 2. Devolve: Capítulo (2 díg) + Posição (4 díg) + descrição da subposição.\n";
        $out .= " 3. Se for CN ou TARIC (8/10 díg), avisa que os direitos efectivos dependem\n";
        $out .= "    de país de origem, medidas TARIC activas e acordos preferenciais.\n";
        $out .= " 4. Fornece link directo para consulta oficial TARIC (pré-preenchido).\n";
        $out .= " 5. Termina com: \"Para classificação vinculativa, solicita IPV à AT.\"\n\n";

        $out .= "CASO B — O UTILIZADOR DESCREVE UMA MERCADORIA:\n";
        $out .= " 1. Pede os dados em falta que são essenciais para classificar:\n";
        $out .= "    · O que é funcionalmente (uso/finalidade)?\n";
        $out .= "    · Material principal (aço, alumínio, plástico, textil, …)?\n";
        $out .= "    · Grau de acabamento (matéria-prima / componente / produto final)?\n";
        $out .= "    · Especificidade sectorial (aviação civil, militar, naval, auto)?\n";
        $out .= "    · Marca/modelo/P.N. (Part Number do fabricante) se disponível.\n";
        $out .= " 2. Aplica as 6 Regras Gerais Interpretativas (RGI) do SH:\n";
        $out .= "    RGI 1: classificação pelos textos das posições e notas de secção/capítulo.\n";
        $out .= "    RGI 2a: artigos incompletos/desmontados classificam como o artigo completo\n";
        $out .= "           desde que apresentem a característica essencial.\n";
        $out .= "    RGI 2b: misturas e composições — posição mais específica prevalece.\n";
        $out .= "    RGI 3a: posição mais específica > genérica.\n";
        $out .= "    RGI 3b: matéria/componente que confere carácter essencial.\n";
        $out .= "    RGI 3c: na dúvida entre duas posições, vence a de NUMERAÇÃO MAIS ALTA.\n";
        $out .= "    RGI 4: mercadoria sem posição exacta → análoga mais próxima.\n";
        $out .= "    RGI 5a/5b: estojos/embalagens classificam-se com o conteúdo.\n";
        $out .= "    RGI 6: subposições comparam-se APENAS ao mesmo nível.\n";
        $out .= " 3. Propõe: Capítulo provável → Posição (4 díg) → 1 a 3 candidatos de\n";
        $out .= "    subposição (6/8 díg) com justificação.\n";
        $out .= " 4. Avisa SEMPRE: \"Classificação preliminar; confirmar em TARIC Consultation\n";
        $out .= "    ou via IPV (Informação Pautal Vinculativa) da AT.\"\n";
        $out .= " 5. Entrega o link TARIC pré-preenchido com o código proposto.\n\n";

        $out .= "EXEMPLOS RÁPIDOS (PartYard):\n";
        $out .= " · \"Rolamento de esferas para bomba industrial\"        → 8482 (rolamentos).\n";
        $out .= " · \"Turbina de um motor A400M\"                         → 8411 (turborreactor).\n";
        $out .= " · \"Parafuso M8 aço inoxidável DIN 912\"                → 7318 (parafusaria).\n";
        $out .= " · \"Válvula de esfera DN50 PN40 aço inox p/ navio\"     → 8481 (válvulas).\n";
        $out .= " · \"Drone de reconhecimento 4 kg com câmara\"           → 8806 (aeronaves n/ tripul.).\n";
        $out .= " · \"Cabo eléctrico 4×2,5 mm² 1 kV\"                      → 8544 (fios e cabos).\n";
        $out .= " · \"Radar de navegação 12 kW banda X para iate\"         → 8526 (radiodetecção).\n";
        $out .= " · \"Fato NBC militar\"                                  → 6210/6211 + verificar Cap. 93.\n";
        $out .= " · \"Boia de sinalização plástica náutica\"              → 8907 (estruturas flutuantes).\n";

        $out .= "\nDISCLAIMER LEGAL:\n";
        $out .= "A classificação pautal é matéria técnica regulada pelo Código Aduaneiro\n";
        $out .= "da União (Reg. UE 952/2013). Classificações dadas pela Logística/PartYard\n";
        $out .= "são preliminares, úteis para orçamentação e pré-documentação, mas NÃO\n";
        $out .= "substituem uma Informação Pautal Vinculativa (IPV/BTI) emitida pela AT\n";
        $out .= "nem o parecer de um despachante oficial. Confirma SEMPRE em TARIC antes\n";
        $out .= "de emitir Factura Comercial / DAU definitivo.\n";
        $out .= "=== FIM PAUTA ADUANEIRA ===\n";

        return $out;
    }

    /**
     * Convenience wrappers (usable by agents via callable tools if desired).
     */
    public static function chapter(string|int $c): ?string
    {
        return Ref::chapter($c);
    }

    public static function lookupUrl(string $code, string $country = 'PT', string $lang = 'pt'): string
    {
        return Ref::taricConsultationUrl($code, $country, $lang);
    }

    public static function lookupHeading(string $code): ?string
    {
        return Ref::lookupHeading($code);
    }
}
