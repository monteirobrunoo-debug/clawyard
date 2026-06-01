# ClawYard — Proposta de Evolução dos Agentes
*Preparado em 2026-06-01. Ancorado na arquitectura actual (30 agentes, AgentManager,
OrchestratorAgent, MultiAgentDebateService, traits de skills, RAG 180 livros, llm-proxy,
dashboards de custo/saúde).*

## Visão
Passar de **"30 agentes que respondem quando perguntados"** para **"uma equipa de IA que
colabora, age proactivamente e se mede a si própria"** — com um único ponto de entrada,
análises cruzadas entre especialistas, robots autónomos que executam tarefas, e um sistema
de desempenho que motiva users e melhora agentes.

Quatro eixos, na ordem que o Bruno pediu.

---

## 1. Um só agente — o **Maestro ClawYard** (orquestração unificada)

**Hoje:** o user escolhe o agente (Marco, Marta, Victor…). O `OrchestratorAgent` já faz
fan-out por keywords, mas o user tem de saber *a quem* perguntar.

**Proposta:** um front-door único — o **Maestro**. O user fala com *um só* assistente; por
trás, o Maestro monta a equipa certa por pergunta e devolve **uma resposta sintetizada**.

**Como (concreto, reaproveita o que existe):**
- **Agentes-como-ferramentas:** todos já partilham a `AgentInterface` (`chat()`). Expor cada
  especialista como uma *tool* que o Maestro (Opus + tool-use loop) pode invocar. Ex.: "preço
  e viabilidade de 200 bombas MTU para a Marinha" → Maestro chama **Marco** (preço) +
  **Dr. Luís** (margem) + **Coronel Rodrigues** (categoria restrita) e funde tudo.
- **Memória de sessão partilhada:** estender o `SharedContextTrait` (já publica contexto) para
  os agentes verem o que os outros disseram *na mesma conversa* → handoff fluido sem repetir.
- **Routing híbrido:** keyword (rápido/barato, já existe) → Maestro só quando a pergunta é
  ambígua ou multi-domínio. Mantém custo controlado.

**Fases:** F1 Maestro escolhe e chama 1 especialista certo · F2 compõe vários numa resposta ·
F3 memória de sessão + handoff transparente.

**Valor:** o user deixa de adivinhar *quem*; o sistema compõe a melhor equipa por pergunta.
Menos atrito, respostas mais completas.

---

## 2. Análises **entre** agentes (rede de revisão + consenso)

**Hoje:** o `MultiAgentDebateService` (debate 3-round, já corrigido hoje) e o `orchestrate()`
(fan-out paralelo via Fibers) já existem — base sólida.

**Proposta:** três padrões de colaboração crítica:
- **Cadeia de revisão (review chain):** pipeline de qualidade. Ex.: Marco **redige** proposta →
  Dr. Luís **valida margem** → ARIA **verifica fuga de confidencial** → Coronel Rodrigues
  **confirma categorias restritas (13/14)** → aprova ou devolve com notas. Cada passo registado.
- **Painel de consenso (voto ponderado):** para decisões caras (OEM exclusivo, fornecedor,
  fabricante) — N agentes votam, a síntese Haiku consolida com *confidence %* (o padrão que o
  debate já usa). Reduz alucinação por diversidade cognitiva (Montreal +13%, MIT/Google −22% erro).
- **Verificação adversarial:** um agente tenta **refutar** a conclusão de outro antes de a dar
  como boa. Mata "plausível mas errado".

**+ UI "War-room":** vista que mostra as tomadas de vários agentes sobre o mesmo tender lado a
lado, com os pontos onde **discordam** destacados — o operador decide com tudo à vista.

**Valor:** mais precisão e menos erros caros em tenders de alto risco; auditabilidade total
(quem disse o quê, e porquê a recomendação final).

---

## 3. "Peças para os robots" — kit de capacidades + agentes **autónomos**

*(Interpreto "robots" = os agentes; "peças" = as ferramentas/skills/dados que os compõem.
Se for robótica/automação industrial literal, ver nota no fim — muda a proposta.)*

**Hoje:** as ferramentas estão dispersas em traits (`WebSearchTrait`, `NsnLookupTrait`,
`TechnicalBookSkillTrait`, `LogisticsSkillTrait`, SAP, QNAP, web-intel). O `ComputerUseAgent`
(RoboDesk) existe mas está subdesenvolvido (responde vazio).

**Proposta:**
- **Catálogo de peças (tool registry):** tornar cada ferramenta uma "peça" *first-class*
  (descrição + schema + custo), num registo central. Cada agente passa a ser **montado a partir
  de um kit** (persona + peças + livros + memória) → uma **matriz de capacidades** mostra que
  agente tem que peças (como o audit de hoje, mas vivo).
- **Peças novas de alto valor:** monitor de portais (auto-watch NSPA/Acingov/TED → alerta +
  importa), preenchimento de formulários (RoboDesk), extracção de catálogos de fornecedor,
  calculadora de margem/frete (Shipping já tem base).
- **Robots autónomos (proactivos, não só chat):** de manhã, um "robot" varre os portais,
  importa, faz triagem com o Quantum, **atribui ao colaborador certo** e prepara o briefing —
  sem ninguém pedir. Estende os crons + o Batch API nightly que já existem.

**Valor:** agentes mais capazes (kit partilhado, sem duplicar código) + trabalho que **acontece
sozinho** (o sistema faz, não espera que perguntem).

---

## 4. Prémios por desempenho — **agentes E users**

**Hoje:** já há dashboards de custo (Fase A2) e saúde dos agentes (Fase A3), `WeeklyActivityReport`,
widget de online users, e o audit de interactividade de hoje. Os dados existem — falta o **placar**.

**Proposta:**
- **Score de agentes:** por agente — taxa de sucesso (sem erro/timeout), latência, 👍/👎 do user,
  tarefas concluídas, custo por tarefa, % de respostas que citam fontes (do audit de hoje).
  → Leaderboard **"Agente do Mês"**. Fecha o ciclo: agente com score baixo → ajustar o prompt.
- **Gamificação dos users:** pontos por **valor real** — tenders ganhos, análises feitas,
  propostas enviadas, deadlines cumpridos, imports/OCR. Badges + leaderboard + **"Colaborador
  do Mês"**.
- **Feedback explícito:** botão 👍/👎 em cada resposta → alimenta o score do agente *e* melhora
  prompts (um RLHF-lite interno).
- **Prémios visíveis:** reconhecimento mensal no dashboard/ticker (estende o widget de online
  users para um "placar").

**Como:** tabelas `agent_scores` + `user_points`, alimentadas pelos eventos **já registados**
(custo, actividade, saúde) + o botão de feedback. Um dashboard de leaderboard.

**Valor:** motiva a equipa (engagement) **e** melhora os agentes com dados; dá-te visibilidade
de **quem** — pessoa ou agente — está a entregar.

---

## Roadmap priorizado

| Prioridade | Item | Esforço | Porquê primeiro |
|---|---|---|---|
| 🥇 1 | **Feedback 👍/👎 + score de agentes** (base do Tema 4) | Baixo | Dados já existem; desbloqueia melhoria contínua + o placar |
| 🥇 2 | **Maestro F1** (tool-router — chama o especialista certo) | Médio | Maior salto de UX; reusa a `AgentInterface` |
| 🥈 3 | **Review chain** (Marco→Luís→ARIA→Rodrigues) no Tema 2 | Médio | Reusa o `MultiAgentDebateService`; reduz erros caros |
| 🥈 4 | **Robot matinal** (varre portais → triagem → atribui → briefing) | Médio | Trabalho proactivo; reusa crons + Batch API |
| 🥉 5 | **Catálogo de peças** + matriz de capacidades | Médio-alto | Fundação para tudo o resto escalar limpo |
| 🥉 6 | **Maestro F2/F3** (multi-especialista + memória de sessão) | Alto | Depende do F1 estabilizado |
| 🥉 7 | **War-room + leaderboard users** | Médio | Polish/visibilidade, depois do motor |

**Quick wins (1-2 dias cada):** botão 👍/👎; "Agente do Mês" simples a partir do dashboard de
saúde existente; alerta matinal de novos tenders por portal.

---

## Nota sobre o Tema 3
Assumi "robots" = os agentes de software. Se quiseres dizer **robótica/automação industrial**
(sourcing de peças para sistemas robóticos como nova vertente de negócio — extensão natural do
marine spare parts), a proposta muda: aí seria um *vertical* novo no ClawYard (catálogo de peças
robóticas, fornecedores, BOM, integração com os tenders de defesa/indústria). Diz-me qual das
duas e detalho.

---

## Princípios transversais (não negociáveis)
- **Segurança primeiro:** tudo passa pelo llm-proxy (redação de confidencial); categorias
  restritas (13/14) e fornecedores CN/RU continuam bloqueados; nada de confidencial para LLM.
- **Custo sob controlo:** routing barato por defeito, Opus só quando vale; budget cap por user
  (Fase B1) e cache semântica (Fase B2) já no sítio.
- **Fiabilidade:** cada novo agente/peça testado no Docker antes de prod; deploy blindado (hoje).
