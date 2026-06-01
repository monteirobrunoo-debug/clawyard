# Inventário dos 4 Temas — o que já existe vs o gap real
*2026-06-01. Baseado em código + uso REAL em produção (não só "o ficheiro existe").*

> Contexto: ao começar a "construir os 4 temas" descobri que **3 já estavam em grande
> parte feitos** — quase dupliquei o sistema de prémios. Este inventário evita gastar
> esforço a reconstruir o que já existe.

## Resumo (1 linha cada)
| Tema | Existe? | Funciona/usado? | Gap REAL |
|---|---|---|---|
| 4 — Prémios | ✅ tudo | ✅ vivo (983 eventos/30d) **mas** 👍/👎 quase não usado (3 votos) | Fazer o feedback ser **usado** + leaderboard de **agentes** |
| 3 — Robótica/peças | ✅ tudo | ✅ usado-ligeiro (6 relatórios, 8 encomendas) | Escalar/promover; confirmar fluxo completo |
| 2 — Análises entre agentes | ✅ debate + orchestrate | ⚠️ **1 debate** — estava **PARTIDO até hoje** (fix de hoje desbloqueou) | **Adopção** + review-chain/war-room |
| 1 — Maestro | 🟡 `auto`+`orchestrator` no chatStream | users escolhem agente específico (mildef 274, crm 159) | **UX central tipo Google** + orquestrador inteligente por defeito |

---

## Tema 4 — Prémios por desempenho
**Existe:** `reward_events` + `user_points` tabelas · `RewardEvent` · `RewardRecorder` ·
badges (`BadgeCatalog`/`BadgeEvaluator`) · leaderboard (`/rewards/leaderboard`) · dashboard
pessoal (`/rewards/me`) · botão 👍/👎 no `welcome.blade` · link na navegação. Desde 28/Abr.

**Uso real (prod):** 983 reward_events (913 nos últimos 30 dias) · 13 users com pontos.
Repartição: `agent_chat`=965 · `tender_imported`=15 · **`agent_thumbs_up`=3** · thumbs_down=0.

**Gap real:** o sistema vive de pontos por *uso* (agent_chat). O **feedback explícito 👍/👎 — o
sinal mais valioso para pontuar agentes — está praticamente vazio (3 votos)**. Provável
problema de discoverability do botão. E falta um **leaderboard de AGENTES** ("Agente do Mês");
o de users existe.

## Tema 3 — Robótica / sourcing de peças
**Existe:** `RobotController` (/robot) · `RobotResearchController` (/robot/research) ·
`RobotResearchReport` (model) · `PartOrder` + `PartOrderController` (retry, download **STL** →
impressão 3D) · `PartSearchService`. "Agents do the writing via cron" — agentes investigam
robôs e encomendam peças **autonomamente** (= os "agentes a ganhar vida").

**Uso real:** 6 relatórios de robô (5 nos últimos 30d) · 8 encomendas de peças (2 nos 30d).
Funciona, uso ligeiro.

**Gap real:** está vivo mas pouco usado. Falta confirmar o fluxo end-to-end (pesquisa →
encomenda → STL → entrega) e promover/escalar. Não é greenfield.

## Tema 2 — Análises entre agentes
**Existe:** `MultiAgentDebateService` (debate 3-round, consenso ponderado) · `orchestrate()`
(fan-out paralelo via Fibers).

**Uso real:** **apenas 1 debate** (status `done`). E **estava partido até hoje** — o bug que
corrigi nesta sessão (`topic` NOT NULL + import `AgentDispatcher` em falta) impedia criar
debates. Ou seja: só HOJE passou a funcionar de verdade.

**Gap real:** **adopção** (agora que funciona) + os padrões que faltam: **cadeia de revisão**
(Marco→Luís→ARIA→Rodrigues) e **war-room** (vista lado-a-lado). A base (debate) já lá está.

## Tema 1 — Maestro (entrada unificada)
**Existe:** o `chatStream` aceita `agent = auto | orchestrator | <específico>`. O modo `auto`
faz auto-route (por keyword, via `AgentManager::route`); `orchestrator` faz fan-out.

**Uso real:** os users escolhem agentes específicos — top: **mildef 274**, crm 159, marketing
34, shipping 33. O `auto`/`orchestrator` parece pouco usado.

**Gap real (o mais "novo" dos 4):** uma **UX central tipo Google** (um campo grande, sem ter de
escolher agente) + o orquestrador como **predefinição inteligente** que compõe vários
especialistas numa resposta (agents-as-tools) e usa o **score de agentes** (Tema 4) para
escolher os melhores.

---

## Gaps reais, priorizados (o que vale mesmo construir)
1. **Tornar o 👍/👎 visível e usado** *(quick)* — sem dados de feedback, o "score de agentes" e o
   routing inteligente do Maestro ficam cegos. É o desbloqueador dos outros.
2. **Maestro — UX central + orquestrador por defeito** *(médio, o genuinamente novo)*.
3. **Adopção do debate + cadeia de revisão** *(médio)* — agora que o debate funciona.
4. **Polir o vertical robótico** *(a confirmar)* — validar o fluxo end-to-end e promover uso.

**Princípio:** auditar antes de construir. 3 dos 4 já existem — o trabalho é **completar e
ligar**, não reconstruir.
