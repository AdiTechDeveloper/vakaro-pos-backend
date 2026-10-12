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
        'models' => [
            'chat'   => [env('GEMINI_TEXT_PRIMARY'), env('GEMINI_TEXT_SECONDARY')],
            'vision' => [env('GEMINI_VISION_PRIMARY'), env('GEMINI_VISION_SECONDARY')],
        ],
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'base_url' => 'https://api.groq.com/openai/v1',
        'models' => [
            'chat'   => [env('GROQ_TEXT_PRIMARY'), env('GROQ_TEXT_SECONDARY')],
            'vision' => [env('GROQ_VISION_PRIMARY'), env('GROQ_VISION_SECONDARY')],
        ],
    ],


];
