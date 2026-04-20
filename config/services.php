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
        'model'       => env('ANTHROPIC_MODEL',        'claude-sonnet-4-6'),
        // Deep reasoning tier — used by Thinking, Briefing, Engineer, Patent, Finance, MilDef.
        // Default: claude-opus-4-5 (known-good). Set ANTHROPIC_MODEL_OPUS in
        // .env to upgrade to claude-opus-4-7 once it is generally available.
        'model_opus'  => env('ANTHROPIC_MODEL_OPUS',   'claude-opus-4-5'),
        // Ultra-fast tier for suggestions/smart-chips. Keep on haiku.
        'model_haiku' => env('ANTHROPIC_MODEL_HAIKU',  'claude-haiku-4-6'),
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
