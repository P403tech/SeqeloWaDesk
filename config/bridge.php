<?php

/*
| Values read here stay available after `php artisan config:cache`.
| Application code must use config('bridge.*'), not env().
*/

return [
    'url' => env('SERVER_URL', ''),
    'token' => env('NODE_WEBHOOK_TOKEN', ''),
    'meta_app_secret' => env('META_APP_SECRET', ''),

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID', ''),
        'token' => env('TWILIO_AUTH_TOKEN', ''),
        'from' => env('TWILIO_WHATSAPP_NUMBER', ''),
    ],

    'ai' => [
        'openai' => env('OPENAI_API_KEY'),
        'anthropic' => env('ANTHROPIC_API_KEY'),
        'gemini' => env('GEMINI_API_KEY'),
        'mistral' => env('MISTRAL_API_KEY'),
        'muse' => env('MUSE_API_KEY'),
        'model' => env('MODEL_API_KEY'),
    ],
];
