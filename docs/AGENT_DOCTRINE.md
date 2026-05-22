# ClawYard Agent Doctrine

> Princípios de design dos agentes ClawYard, derivados de Bornet, Wirtz, Davenport et al. (2025) *"Agentic Artificial Intelligence: Harnessing AI Agents to Reinvent Business, Work, and Life"* (World Scientific). Última actualização: 2026-05-22.

Este documento é o **mapa de patterns** que aplicamos aos agentes (Cor. Rodrigues, Marco Sales, Eng. Victor, Capitão Vasco, Ana RH, Marta, Daniel, etc.). Quando adicionares um agente novo ou alterares um existente, verifica que respeita as 3 colunas (Action, Reasoning, Memory) e os 7 patterns listados.

---

## As Três Pedras Angulares (Bornet 2025, Cap 5-7)

| Pedra | O que é | Status ClawYard |
|---|---|---|
| **Action** | Ferramentas para EXECUTAR (não só pensar) | ✅ 5 AgentTools, AutonomousAgentRunner com tool-use loop |
| **Reasoning** | Pausar para pensar quando vale a pena (extended thinking, debate) | ✅ Extended thinking em Eng. Victor + Cor. Rodrigues; ⚠️ não há multi-agent debate ainda |
| **Memory** | Não começar de zero em cada chat | ✅ Implementado 2026-05-22 — `agent_memories` + `AgentMemoryTrait` (MilDef primeiro, outros a seguir) |

---

## 7 Patterns Obrigatórios

### 1. Tool Definitions em 5 Componentes (+52% reliability)

**Fonte:** Ruan et al. 2023 ("TPTU: Large Language Model-based AI Agents for Task Planning and Tool Usage"), citado em Bornet Cap 5 p. 136.

Cada tool em `app/Services/AgentTools/*.php` tem `description()` com:

1. **IDENTITY** — nome único + propósito em uma frase
2. **INPUT** — parâmetros obrigatórios + opcionais + formatos
3. **OUTPUT** — o que devolve + formato
4. **CONSTRAINTS** — quando usar / quando NÃO usar (anti-overlap com outros tools)
5. **ERRORS** — failure modes + instrução de recovery para o LLM

**Exemplo correcto:** ver `NsnLookupTool::description()`.

### 2. Tool Overload — Máximo 6 Tools por Agente

**Fonte:** Bornet Cap 5 p. 135 ("half a dozen tools may represent a practical maximum").

Acima de 6 tools, o LLM começa a:
- Hallucinar inputs
- Hesitar entre tools sobrepostas
- Perder o fio à meada

ClawYard hoje: 5 tools no roster (web_search, tender_search, tender_attachments, book_search, nsn_lookup). Margem para 1 mais. Se quiseres adicionar, primeiro deprecia outra.

### 3. Tool Resilience — Control × Impact Matrix

**Fonte:** Bornet Cap 5 p. 148.

Toda a tool externa deve ter:
- **Fallback automático** quando o primário falha
- **Escalada explícita** ao operador humano quando todos os fallbacks falham

ClawYard hoje:
- ✅ Tavily fail → tool devolve `ok:false` com mensagem para o LLM continuar sem
- ✅ NSN sem hits → mensagem clara ao user
- ⚠️ SAP B1 down → ainda não há fallback consistente; HrAgent tem try/catch graceful
- ❌ Anthropic API down → não há fallback (single provider)

### 4. Long-Term Memory por Utilizador

**Fonte:** Bornet Cap 7 ("Memory: Building AI That Learns").

LLMs em produção têm "memória de peixe-dourado" — começam de zero. Estudos citados:
- Healthcare scheduling: +70% velocidade, +45% satisfação com LTM
- Customer service: utilizadores repetem-se 3-4× sem LTM, satisfaction tanks

ClawYard hoje:
- ✅ `agent_memories` table (migration 2026_05_22)
- ✅ `AgentMemoryTrait` com `prependMemories()` + `maybeExtractAndSaveMemories()`
- ✅ Ligado em `MilDefAgent` (Cor. Rodrigues)
- ⏳ TODO: ligar nos outros 3 agentes em chat (Eng. Victor, Capitão Vasco, Marco Sales) + Ana RH

**Como invocar:** o user escreve `lembra-te que prefiro MTU AG Friedrichshafen` ou `anota: o cliente exige certificação NATO STANAG 4569 nivel 4`. Ou o LLM emite `<save_memory key="..." value="..."/>` que o trait apanha e remove do output.

### 5. Confirmação Explícita Antes de Operações Caras

**Fonte:** Bornet Cap 5 "When Tools Meet Trust" (p. 154) + Cap 11 "When Agents Go Rogue".

Não auto-disparar operações que custam >€1 ou são irreversíveis. Implementado 2026-05-22 (commits `840d3d2` + `a9de3b9`):
- ✅ Análise multi-agente: cron desligada, dispatch em PDF upload desligado, botão manual com `window.confirm()` mostrando custo da última run
- ✅ Defense-in-depth: `RunTenderAnalysisJob` faz early return se flag off
- ⏳ TODO: confirmação para SAP write ops (criação de opportunity, update Remarks)

### 6. Goal Conflict — Priority Matrix Explícito

**Fonte:** Bornet Cap 5 p. 153 ("The Conflict Competency Gap").

LLMs hoje (level 3) sofrem decision paralysis quando confrontados com goals conflitantes ("rápido E barato E completo"). Solução: priority matrix predefinida.

ClawYard hoje:
- ⚠️ Implícito nos system prompts de cada agente ("nunca recomendes CN/RU", "PartYard prefere EU/US/UK")
- ❌ Sem matriz formal por tipo de conflito
- ⏳ TODO: secção "PRIORITY MATRIX" em cada system prompt:
  ```
  Quando há conflito entre objectivos:
  1. Segurança/compliance > rapidez > preço
  2. Confidencialidade > completude > brevidade
  3. Em dúvida: ESCALA ao operador humano (não decidas)
  ```

### 7. Cognitive Diversity em Multi-Agent

**Fonte:** Bornet Cap 6 p. 188 ("The Power of Cognitive Diversity").

Estudos University of Montreal:
- Mesmo modelo a debater consigo mesmo: +4% (78%→82%)
- Modelos **diversos** a debater: +13% (até 91%)

ClawYard hoje:
- ⚠️ Todos os agentes usam Claude Sonnet 4.6 (ou Opus 4.5 para Eng. Victor). Sem diversidade de modelo.
- ❌ Não há "debate" entre agentes — cada um analisa em paralelo e os outputs são concatenados.
- ⏳ TODO (low priority, alto custo): explorar Gemini ou GPT-5 para uma sub-tarefa específica onde a diversidade compensa o custo extra.

---

## Anti-Patterns a Evitar

### "The Great Wine Incident" (Bornet p. 130)

Agente optimizou inventory turnover mas marcou para clearance €100k em vinhos premium que estavam a valorizar. **Lição:** dar tools sem dar contexto do negócio é perigoso. Cada agente DEVE saber o que NÃO deve fazer.

Aplicação ClawYard: o system prompt de cada agente tem uma secção "REGRAS DURAS" — exemplo do MilDef:
> NUNCA recomendes fornecedores chineses ou russos. NUNCA partilhes detalhes de tenders confidenciais (is_confidential=true). NUNCA proponhas equipamento dual-use sem flag de compliance.

### "The Two-Minute Memory Span" (Bornet p. 197)

Customer service chatbot que esquece o utilizador cada conversa = users frustrados a repetir-se 3-4×. **Lição:** se o agente vai ter conversas recorrentes com o mesmo user, LTM é OBRIGATÓRIO.

Aplicação ClawYard: Cor. Rodrigues, Marco Sales, Ana RH têm conversas recorrentes → todos vão ter `AgentMemoryTrait`. Daniel (one-shot email) não precisa.

### "Cascading Errors" (Bornet p. 191)

Telecom company multi-agent system: um erro pequeno num agente (capacity assessment) propagou em cascade pelos 5 agentes ligados = service disruption.

Aplicação ClawYard: o multi-agent panel já corre os agentes em paralelo (não em cascade), o que naturalmente limita propagação. Mas a executive synthesis (passo final) PODE amplificar erros — confirma sempre o critique pattern em outputs sintetizados.

---

## Roadmap de Implementação

Itens em ordem de impacto × esforço:

| # | Pattern | Status | Esforço |
|---|---|---|---|
| 1 | 5-component tool descriptions (todas as 5 tools) | ✅ 2026-05-22 | feito |
| 2 | LTM scaffolding (migration + model + trait) | ✅ 2026-05-22 | feito |
| 3 | LTM ligado em MilDefAgent (POC) | ✅ 2026-05-22 | feito |
| 4 | LTM ligado em Eng. Victor, Capitão Vasco, Marco Sales | ⏳ próximo | 30 min |
| 5 | LTM ligado em Ana RH, Marta, Daniel | ⏳ semana | 60 min |
| 6 | UI panel "Memórias deste agente" no perfil do user | ⏳ semana | 2-3h |
| 7 | Priority matrix em cada system prompt | ⏳ mês | 1h por agente |
| 8 | Multi-agent debate (cognitive diversity) | ⏳ trimestre | grande |
| 9 | Fallback Anthropic→OpenAI quando API down | ⏳ trimestre | médio |

---

## Referências

1. Bornet, P., Wirtz, J., Davenport, T.H., De Cremer, D., Evergreen, B., Fersht, P., Gohel, R., Khiyara, S. (2025). *Agentic Artificial Intelligence: Harnessing AI Agents to Reinvent Business, Work, and Life*. World Scientific. ISBN 978-981-98-1566-1.
2. Ruan, J., et al. (2023). "TPTU: Large Language Model-based AI Agents for Task Planning and Tool Usage." [arXiv:2308.03427](https://arxiv.org/abs/2308.03427).
3. Du, Y., et al. (2023). "Improving Factuality and Reasoning in Language Models through Multiagent Debate." [arXiv:2305.14325](https://arxiv.org/abs/2305.14325).
4. Saha, S., et al. (2023). "Can Language Models Teach Weaker Agents?" [arXiv:2306.09299](https://arxiv.org/abs/2306.09299).
5. Shi, Z., et al. (2024). "Learning to Use Tools via Cooperative and Interactive Agents." [arXiv:2403.03031](https://arxiv.org/abs/2403.03031).
