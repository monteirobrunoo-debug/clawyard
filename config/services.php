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

    // RoboDesk — local Mac bridge for Computer Use API
    'robodesk' => [
        'bridge_url' => env('ROBODESK_BRIDGE_URL'),                           // e.g. https://xyz.ngrok-free.app
        'secret'     => env('ROBODESK_SECRET', ''),                           // shared secret
        'cu_model'   => env('ROBODESK_MODEL',   'claude-3-5-sonnet-20241022'),// Computer Use model
        'cu_beta'    => env('ROBODESK_CU_BETA', 'computer-use-2024-10-22'),   // beta flag
        'cu_tool'    => env('ROBODESK_CU_TOOL', 'computer_20241022'),         // tool type
    ],

];
