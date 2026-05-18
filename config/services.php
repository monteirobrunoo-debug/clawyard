<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'nvidia' => [
        'api_key'  => env('NVIDIA_API_KEY'),
        'base_url' => env('NVIDIA_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
        'model'    => env('NVIDIA_MODEL', 'meta/llama-3.1-8b-instruct'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // Default (fast, high-volume agents: Sales, Support, Email, CRM, Claude chat…)
        // `claude-sonnet-4-6` alias validated 2026-04-22 — resolves to the
        // newest 4.6 sonnet snapshot on Anthropic's side.
        'model'       => env('ANTHROPIC_MODEL',        'claude-sonnet-4-6'),
        // Deep reasoning tier — used by Thinking, Briefing, Engineer, Patent, Finance, MilDef.
        // Alias `claude-opus-4-5` validated 2026-04-22. Do NOT pin to a
        // dated snapshot without re-probing (`claude-opus-4-5-20250929`
        // returned 404).
        'model_opus'  => env('ANTHROPIC_MODEL_OPUS',   'claude-opus-4-5'),
        // Ultra-fast tier for suggestions/smart-chips. Keep on haiku.
        // NOTE: `claude-haiku-4-6` does NOT exist (probe returned 404) —
        // haiku is still at 4.5. Pin to the dated 4.5 snapshot we validated
        // so the env-less fallback never explodes.
        'model_haiku' => env('ANTHROPIC_MODEL_HAIKU',  'claude-haiku-4-5-20251001'),
        // 2026-05-18: modelo usado pelo AgentSelfCritique (segunda pass de
        // validação contra hallucinations). Default = mesmo Sonnet do agente
        // principal. Pode-se forçar Opus via env para validação mais rigorosa
        // em troca de +latência e +custo. Ver app/Services/AgentSelfCritique.
        'critique_model' => env('ANTHROPIC_CRITIQUE_MODEL', env('ANTHROPIC_MODEL', 'claude-sonnet-4-5')),
        // ── Egress control ────────────────────────────────────────────────
        // Upstream base URI. Defaults to Anthropic's public API. Override
        // with ANTHROPIC_BASE_URL to point every agent through the company
        // Digital Ocean proxy (e.g. https://llm-proxy.partyard.eu) — the
        // proxy can then log, redact PII and enforce rate limits in-house
        // before forwarding to Anthropic. Every agent reads this via
        // AnthropicKeyTrait::anthropicBaseUri() so flipping one env var
        // reroutes ALL 24 agents.
        'base_uri'    => env('ANTHROPIC_BASE_URL',     'https://api.anthropic.com'),
        // PII redaction — when true, prompts are scrubbed (emails, phones,
        // NIF, IBAN, card numbers, passwords) BEFORE leaving the server.
        // Default false so existing behaviour is preserved; flip per-env
        // to enforce. See App\Support\PiiRedactor.
        'redact_pii'  => env('ANTHROPIC_REDACT_PII',   false),

        // Split-VM HMAC auth between app tier and proxy tier.
        // When set, AnthropicKeyTrait::anthropicGuzzleClient() attaches a
        // signing middleware that proves the request came from an authorised
        // app VM. Matching key goes into the proxy VM's PY_PROXY_SHARED_KEY
        // env. See doc/vm-separation.md + llm-proxy/auth.py.
        //
        // Empty = loopback topology (app and proxy share a VM). No signing.
        'proxy_shared_key'      => env('PY_PROXY_SHARED_KEY',       ''),
        'proxy_shared_key_next' => env('PY_PROXY_SHARED_KEY_NEXT',  ''),

        // When the internal proxy uses a self-signed TLS cert (see
        // doc/vm-separation.md §7), set this to the absolute path of the
        // pinned CA bundle on the app VM. Leave empty for public CAs.
        'proxy_ca_bundle' => env('ANTHROPIC_PROXY_CA_BUNDLE', ''),
    ],

    // ── Hybrid model routing (see doc/hybrid-model.md) ──────────────────────
    // When `enabled=true`, ModelRoutingTrait routes high-sensitivity prompts
    // (per SensitivityClassifier) to a locally-hosted model instead of
    // Anthropic. Low/medium tiers continue to use the external path. With
    // `enabled=false` the routing trait is a straight Claude passthrough
    // and the classifier only emits a log line for offline analysis.
    'hybrid' => [
        'enabled'        => env('HYBRID_ROUTING_ENABLED', false),
        'local_endpoint' => env('HYBRID_LOCAL_ENDPOINT', ''),  // e.g. http://127.0.0.1:11434/v1/chat/completions
        'local_model'    => env('HYBRID_LOCAL_MODEL', 'llama3.1:8b-instruct-q5_K_M'),
    ],

    'patentsview' => [
        'api_key' => env('PATENTSVIEW_API_KEY'),
    ],

    'tavily' => [
        'api_key' => env('TAVILY_API_KEY'),
    ],

    'aria' => [
        // When true, AriaAgent will include the internal SAP B1 endpoint in
        // live-site checks. Defaults to false to avoid leaking the endpoint
        // existence over the LLM channel and to prevent DoS amplification
        // (users triggering ARIA → ARIA hammers SAP).
        'probe_sap' => env('ARIA_PROBE_SAP', false),
    ],

    // ── Embeddings (biblioteca técnica + futuras integrações RAG) ────────
    // Via NVIDIA NIM. Usa as mesmas credenciais services.nvidia.api_key.
    // nv-embedqa-e5-v5 = 1024 dims · multilíngue · optimizado para retrieval.
    'embedding' => [
        'model' => env('EMBEDDING_MODEL', 'nvidia/nv-embedqa-e5-v5'),
    ],

    // ── Eng. Repair / Work Report App standalone ─────────────────────────
    // Bridge HTTP entre clawyard e o app Python Flask em /Volumes/Public/
    // IT Division/PY_Work_Report/Work_Report_App/. Fornece vision API para
    // análise de fotos + biblioteca técnica embebida.
    'work_report' => [
        'url'   => env('WORK_REPORT_APP_URL', ''),     // ex: http://10.0.0.5:5050
        'token' => env('WORK_REPORT_APP_TOKEN', ''),    // X-Bridge-Token shared secret
    ],

    // ── Briefing Agent (Strategist Renato) — provider switcher ──────────────
    // Default: Anthropic Claude Opus 4.5 (deep reasoning, long context).
    // Alternative: NVIDIA Nemotron Super 49B v1.5 (open model, free inference
    // on the integrate.api.nvidia.com endpoint we already use for Carlos).
    //
    // Flip BRIEFING_PROVIDER=nvidia in the .env to swap. No code change needed.
    // Useful for A/B-testing strategic briefings cost-vs-quality without
    // touching every other Anthropic-backed agent.
    'briefing' => [
        'provider'     => env('BRIEFING_PROVIDER',     'anthropic'),  // anthropic | nvidia
        'nvidia_model' => env('BRIEFING_NVIDIA_MODEL', 'nvidia/llama-3.3-nemotron-super-49b-v1.5'),
    ],

    'deploy_token' => env('DEPLOY_TOKEN', ''),

    'sap' => [
        'base_url'   => env('SAP_B1_URL', 'https://sld.partyard.privatcloud.biz/b1s/v1'),
        'company'    => env('SAP_B1_COMPANY', 'PARTYARD'),
        'username'   => env('SAP_B1_USER', ''),
        'password'   => env('SAP_B1_PASSWORD', ''),
        // Set SAP_TLS_VERIFY=/etc/ssl/certs/sap-ca.pem in production to pin
        // the SAP CA cert. SAP_TLS_VERIFY=false is a fallback only.
        'tls_verify' => env('SAP_TLS_VERIFY', true),
    ],

    // Agent swarm — autonomous multi-agent chains that produce
    // LeadOpportunity rows from business signals (tenders, emails,
    // equipment queries). Budget caps are enforced at runtime by
    // App\Services\AgentSwarm\AgentSwarmRunner — exceed → graceful
    // abort, never a surprise charge. See migrations + service.
    'agent_swarm' => [
        // Hard cap per chain execution. The default is conservative
        // ($0.10) — enough for ~5 cheap LLM calls + 1 synthesis. Bump
        // when signals get richer.
        'max_cost_per_run' => (float) env('AGENT_SWARM_MAX_COST_PER_RUN', 0.10),
        // Daily aggregate cap across ALL chain runs. Rolling 24h
        // window starting at midnight server-local. Exceed → all
        // subsequent runs abort with reason='daily_budget_exceeded'.
        'max_cost_per_day' => (float) env('AGENT_SWARM_MAX_COST_PER_DAY', 5.00),

        // Per-call HTTP timeout for AgentDispatcher → Anthropic.
        // 60s covers slow synthesis with extended thinking but still
        // forces the runner to move on if upstream hangs.
        'dispatch_timeout_seconds' => (int) env('AGENT_SWARM_DISPATCH_TIMEOUT', 60),

        // Per-1M-token rates in USD. Keys are partial model-name
        // matches (e.g. 'sonnet' matches 'claude-sonnet-4-6-…').
        // Defaults track Anthropic's published pricing as of 2026-04;
        // override via AGENT_SWARM_RATES env to a JSON string when
        // their card changes. AgentDispatcher::matchModel falls back
        // to tier inference if no key matches.
        'token_rates' => json_decode(
            env('AGENT_SWARM_RATES', '{"haiku":{"input":1.0,"output":5.0},"sonnet":{"input":3.0,"output":15.0},"opus":{"input":15.0,"output":75.0}}'),
            true,
        ) ?: [
            'haiku'  => ['input' => 1.0,  'output' => 5.0],
            'sonnet' => ['input' => 3.0,  'output' => 15.0],
            'opus'   => ['input' => 15.0, 'output' => 75.0],
        ],
    ],

    // hp-history — pgvector-backed company memory served from a separate
    // DigitalOcean droplet (`hp-history.partyard.eu`). Indexes historical
    // PDFs, emails, proposals and contracts so agents can cite precedents
    // ("last time we sold MTU spares to PT Navy was 2023, €54k order, see
    // ref XYZ"). Optional service: when `enabled=false` the agent
    // augmentation degrades silently and Marco/Vasco continue with their
    // partner-network + web-search context.
    'hp_history' => [
        'enabled'     => env('HP_HISTORY_ENABLED', false),
        'base_url'    => env('HP_HISTORY_BASE_URL', ''),    // e.g. https://hp-history.partyard.eu
        // HMAC-SHA256 shared secret used to sign every request. Rotate
        // on both sides simultaneously; rejection of a request with a
        // valid-but-stale signature is the canary for rotation drift.
        'hmac_secret' => env('HP_HISTORY_HMAC_SECRET', ''),
        'timeout'     => (int) env('HP_HISTORY_TIMEOUT_SECONDS', 8),
        // Cache TTL in seconds. Same prompt within this window reuses
        // the previous response — mostly to avoid re-embedding on
        // back-to-back follow-ups in the same conversation.
        'cache_ttl'   => (int) env('HP_HISTORY_CACHE_TTL', 300),
        // Maximum chunks the server may return; defaults match what
        // the LLM context can comfortably absorb without crowding out
        // the partner-workshop block.
        'max_results' => (int) env('HP_HISTORY_MAX_RESULTS', 5),
    ],

    'whatsapp' => [
        'token'        => env('META_WHATSAPP_TOKEN'),
        'phone_id'     => env('META_WHATSAPP_PHONE_ID'),
        'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN', 'clawyard_webhook_2026'),
        // SECURITY: HMAC-SHA256 shared secret from Meta dev portal. Webhook
        // requests without a valid X-Hub-Signature-256 signature are rejected.
        'app_secret'   => env('META_APP_SECRET', ''),
    ],

    'epo' => [
        'consumer_key'    => env('EPO_CONSUMER_KEY'),
        'consumer_secret' => env('EPO_CONSUMER_SECRET'),
    ],

    'acingov' => [
        'username'   => env('ACINGOV_USERNAME'),
        'password'   => env('ACINGOV_PASSWORD'),
        // Defaults to true — acingov.pt has a valid Let's Encrypt cert.
        'tls_verify' => env('ACINGOV_TLS_VERIFY', true),
    ],

    'vortal' => [
        'username' => env('VORTAL_USERNAME'),
        'password' => env('VORTAL_PASSWORD'),
    ],

    'unido' => [
        'username' => env('UNIDO_USERNAME'),
        'password' => env('UNIDO_PASSWORD'),
    ],

    'ungm' => [
        'username' => env('UNGM_USERNAME'),
        'password' => env('UNGM_PASSWORD'),
    ],

    'samgov' => [
        'api_key' => env('SAM_GOV_API_KEY'),
    ],

    // VesselTracker.com — AIS realtime + shipowner directory.
    // Usado por Marco Sales + Capitão Vasco para lead-gen marítimo
    // (extrai armadores, navios, motores e contactos para outreach).
    'vesseltracker' => [
        'username' => env('VESSELTRACKER_USERNAME'),
        'password' => env('VESSELTRACKER_PASSWORD'),
    ],


    // RoboDesk — local Mac bridge for Computer Use API
    'robodesk' => [
        'bridge_url' => env('ROBODESK_BRIDGE_URL'),                           // e.g. https://xyz.ngrok-free.app
        'secret'     => env('ROBODESK_SECRET', ''),                           // shared secret
        'cu_model'   => env('ROBODESK_MODEL',   'claude-3-5-sonnet-20241022'),// Computer Use model
        'cu_beta'    => env('ROBODESK_CU_BETA', 'computer-use-2024-10-22'),   // beta flag
        'cu_tool'    => env('ROBODESK_CU_TOOL', 'computer_20241022'),         // tool type
    ],

    // 2026-05-18: Auto-crítica / second-pass validation contra hallucinations.
    // Quando ENABLED_ON_SHARES=true, cada turn de chat externo (agent share)
    // termina com uma chamada Claude extra que avalia o output sob 5 critérios
    // (factualidade, hedging, alternativas, citações, consistência). O frontend
    // recebe um SSE event "critique" e mostra um badge no fim da resposta.
    //
    // Pedido directo do operador:
    //   "O resultado dos agentes tem de ser sempre verdadeiro, e tentar
    //    validar sempre a melhor opção, por isso cria mecanismos de crítica
    //    e auto-prompts para ter os melhores resultados"
    //
    // Custo: +1 LLM call por turn (~+30-50% custo). Para evitar abusos:
    //   • Drafts < 200 chars saltam automaticamente
    //   • Drafts com tokens __TABLE__/__CHART__/__EMAIL__ saltam (já validados)
    //   • Cache 5 min para mesmos (prompt, draft) idênticos
    'agent_critique' => [
        // Master switch — corre crítica em agent shares (canal externo, alto risco)
        'enabled_on_shares' => env('AGENT_CRITIQUE_SHARES',  true),
        // Também correr em chat interno do dashboard (sempre que NvidiaController
        // termina um turn de Claude). Default OFF (poupar custos quando o
        // utilizador é interno e pode verificar manualmente).
        'enabled_on_internal' => env('AGENT_CRITIQUE_INTERNAL', false),
    ],

];
