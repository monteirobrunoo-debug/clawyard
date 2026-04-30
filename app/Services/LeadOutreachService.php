<?php

namespace App\Services;

use App\Models\LeadOpportunity;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Drafts a cold-outreach email for a confident lead.
 *
 * Why a dedicated service vs. reusing AgentSwarmRunner:
 *   • Outreach drafting is a single LLM call — the swarm machinery
 *     is overkill (no chain, no synthesiser, no chain_log).
 *   • The output schema is structured (subject + body + recipient),
 *     not free-form prose, so we want a tight system prompt with
 *     hard format rules.
 *   • Costs are tracked separately (outreach_draft_cost_usd) so the
 *     finance dashboard can answer "how much did the cold-outreach
 *     drafter cost us last month?".
 *
 * The actual SEND is a separate, manager-gated step — see
 * LeadOpportunityController::sendOutreach. We never touch SMTP from
 * inside this service.
 */
class LeadOutreachService
{
    public function __construct(
        private AgentDispatcher $dispatcher,
    ) {}

    /**
     * Generate a draft for one lead. Mutates the model + saves it.
     *
     * Returns:
     *   ['ok' => true,  'lead' => $lead]                  — draft generated
     *   ['ok' => false, 'error' => 'reason', 'lead' => $lead]
     *
     * Idempotent — repeat calls with outreach_drafted_at already set
     * will overwrite the old draft (useful for "regenerate" button).
     */
    public function draftFor(LeadOpportunity $lead, bool $regenerate = false): array
    {
        if (!$regenerate
            && $lead->outreach_status !== LeadOpportunity::OUTREACH_NONE
            && $lead->outreach_drafted_at !== null
        ) {
            return ['ok' => false, 'error' => 'already_drafted', 'lead' => $lead];
        }

        if ($lead->status !== LeadOpportunity::STATUS_CONFIDENT) {
            return ['ok' => false, 'error' => 'not_confident_status', 'lead' => $lead];
        }

        $system = $this->buildSystemPrompt();
        $user   = $this->buildUserMessage($lead);

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $user,
            maxTokens:    1200,
        );

        if (!($res['ok'] ?? false)) {
            Log::warning('LeadOutreachService: dispatcher failed', [
                'lead_id' => $lead->id,
                'error'   => $res['error'] ?? 'unknown',
            ]);
            return ['ok' => false, 'error' => $res['error'] ?? 'dispatcher_error', 'lead' => $lead];
        }

        $parsed = $this->parseResponse((string) ($res['text'] ?? ''));
        if (!$parsed) {
            Log::warning('LeadOutreachService: could not parse LLM output', [
                'lead_id' => $lead->id,
                'raw'     => mb_substr((string) ($res['text'] ?? ''), 0, 400),
            ]);
            return ['ok' => false, 'error' => 'parse_failed', 'lead' => $lead];
        }

        $lead->outreach_status         = LeadOpportunity::OUTREACH_DRAFT_PENDING;
        $lead->outreach_draft_subject  = mb_substr($parsed['subject'], 0, 255);
        $lead->outreach_draft_body     = $parsed['body'];
        $lead->outreach_to_email       = $parsed['to_email'] ?: null;
        $lead->outreach_to_name        = $parsed['to_name']  ?: null;
        $lead->outreach_drafted_at     = now();
        $lead->outreach_draft_cost_usd = (float) ($res['cost_usd'] ?? 0);
        // Reset downstream gates if we regenerated.
        if ($regenerate) {
            $lead->outreach_approved_at         = null;
            $lead->outreach_approved_by_user_id = null;
            $lead->outreach_sent_at             = null;
            $lead->outreach_sent_by_user_id     = null;
            $lead->outreach_reject_reason       = null;
        }
        $lead->save();

        return ['ok' => true, 'lead' => $lead];
    }

    private function buildSystemPrompt(): string
    {
        // Hand-tuned for cold outreach in PT-PT. We deliberately do NOT
        // use PromptLibrary::commercial() because that template is for
        // chat-style sales support; outreach needs the strict subject+
        // body format that downstream parsing depends on.
        return <<<PROMPT
És o Marco, sales lead da HP-Group / PartYard, especialista em motores e serviços marítimos
(MTU, CAT, MAK, Jenbacher, SKF, Schottel) e em peças sobressalentes para frotas comerciais e
militares. A tua missão neste prompt é escrever um email frio de primeira abordagem para um
contacto identificado como oportunidade pelo nosso swarm de agentes.

━━ TOM ━━
- Profissional, directo, sem jargão americano. Português europeu (pt-PT).
- Tratar o destinatário com formalidade ("estimado", "exmo./exma.") quando o nome é conhecido,
  caso contrário um cumprimento neutro ("Bom dia").
- Curto. Máximo 180 palavras no corpo. Se o lead for genérico, ainda mais curto.
- Sem promessas exageradas, sem superlativos vazios ("revolucionário", "líder mundial").

━━ ESTRUTURA OBRIGATÓRIA ━━
1. Saudação personalizada
2. Frase 1: contexto que mostra que conheces o cliente (use o "summary" do lead)
3. Frase 2-3: o que oferecemos relevante para o problema dele
4. CTA simples: "marcamos 15 min esta semana?" ou "envio uma proposta detalhada?"
5. Assinatura:
   Marco — HP-Group / PartYard
   marco@partyard.eu

━━ FORMATO DE SAÍDA (CRÍTICO) ━━
Devolve APENAS o JSON abaixo, nada mais. Sem markdown fences, sem texto antes ou depois:

{"to_email":"<email se conheceres, senão string vazia>","to_name":"<nome se conheceres, senão string vazia>","subject":"<assunto curto, ≤80 chars>","body":"<corpo do email com \\n para quebra de linha>"}

━━ REGRAS DURAS ━━
- NUNCA inventes números de peça, preços, prazos, certificações ou referências. Fica genérico.
- NUNCA inventes um email do destinatário. Se não souberes, devolve to_email "".
- NÃO incluas anexos ou links a páginas internas — não temos URLs públicas estáveis.
- NÃO uses emojis no assunto. Um único emoji no corpo é tolerável; mais é spam-like.
PROMPT;
    }

    private function buildUserMessage(LeadOpportunity $lead): string
    {
        $customer  = trim((string) $lead->customer_hint)  ?: '(cliente não identificado)';
        $equipment = trim((string) $lead->equipment_hint) ?: '(equipamento não identificado)';
        $signal    = trim((string) $lead->source_signal_type) ?: '(sinal desconhecido)';

        $summary = trim((string) $lead->summary);
        $title   = trim((string) $lead->title);

        return <<<MSG
Lead identificado pelo swarm:

• Título: {$title}
• Score: {$lead->score}/100
• Cliente provável: {$customer}
• Equipamento referido: {$equipment}
• Tipo de sinal: {$signal}

Resumo do contexto sintetizado pelos agentes:
{$summary}

Tarefa: escreve um email frio de primeira abordagem da Marco (HP-Group/PartYard) para
este contacto. Segue ESTRITAMENTE a estrutura e o formato JSON definidos no system prompt.
Se não tiveres email do destinatário, devolve to_email "" — o manager preencherá manualmente.
MSG;
    }

    /**
     * Pull subject/body/recipient out of the LLM response. We accept
     * either a clean JSON blob or a JSON blob wrapped in stray text
     * (the model occasionally adds "Aqui está:" preambles despite the
     * prompt). Returns null when nothing usable came back.
     *
     * @return array{subject:string,body:string,to_email:string,to_name:string}|null
     */
    private function parseResponse(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // Strip markdown code fences if present (```json … ```).
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw) ?? $raw;

        // Find the first {...} block — the LLM might prepend filler.
        if (!preg_match('/\{.*\}/s', $raw, $m)) {
            return null;
        }

        $decoded = json_decode($m[0], true);
        if (!is_array($decoded)) return null;

        $subject = trim((string) ($decoded['subject'] ?? ''));
        $body    = trim((string) ($decoded['body']    ?? ''));
        $email   = trim((string) ($decoded['to_email'] ?? ''));
        $name    = trim((string) ($decoded['to_name']  ?? ''));

        if ($subject === '' || $body === '') return null;

        // Sanity-check the email shape — anything that doesn't smell
        // like an email gets dropped (the manager can fill it in).
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        return [
            'subject'  => $subject,
            'body'     => $body,
            'to_email' => $email,
            'to_name'  => $name,
        ];
    }
}
