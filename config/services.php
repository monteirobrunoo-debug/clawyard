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
        'model'   => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
    ],

    'patentsview' => [
        'api_key' => env('PATENTSVIEW_API_KEY'),
    ],

    'tavily' => [
        'api_key' => env('TAVILY_API_KEY'),
    ],

    'deploy_token' => env('DEPLOY_TOKEN', ''),

    'sap' => [
        'base_url' => env('SAP_B1_URL', 'https://sld.partyard.privatcloud.biz/b1s/v1'),
        'company'  => env('SAP_B1_COMPANY', 'PARTYARD'),
        'username' => env('SAP_B1_USER', ''),
        'password' => env('SAP_B1_PASSWORD', ''),
    ],

    'whatsapp' => [
        'token'        => env('META_WHATSAPP_TOKEN'),
        'phone_id'     => env('META_WHATSAPP_PHONE_ID'),
        'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN', 'clawyard_webhook_2026'),
    ],

    'epo' => [
        'consumer_key'    => env('EPO_CONSUMER_KEY'),
        'consumer_secret' => env('EPO_CONSUMER_SECRET'),
    ],

    'acingov' => [
        'username' => env('ACINGOV_USERNAME'),
        'password' => env('ACINGOV_PASSWORD'),
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

];
