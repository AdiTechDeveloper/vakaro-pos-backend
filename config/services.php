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

    // 'groq' => [
    //     'api_key' => env('GROQ_API_KEY'),
    //     'base_url' => 'https://api.groq.com/openai/v1',
    // ],

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

    // 'gemini' => [
    //     'api_key' => env('GEMINI_API_KEY'),
    //     'model' => 'gemini-3.8-flash',
    // ],


    'textbee' => [
        'api_key' => env('TEXTBEE_API_KEY'),
        'base_url' => env('TEXTBEE_BASE_URL'),
        'device_id' => env('TEXTBEE_DEVICE_ID'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'vision_model' => env(
            'GEMINI_VISION_MODEL',
            'gemini-3.1-flash-lite'
        ),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'base_url' => 'https://api.groq.com/openai/v1',
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
        'vision_model' => env(
            'GROQ_VISION_MODEL',
            'qwen/qwen3.8-27b'
        ),
    ],


];
