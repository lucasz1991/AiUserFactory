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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'webaidetective_base' => [
        'scraper_profile_sync_url' => env('WEBAIDETECTIVE_BASE_API_URL'),
        'scraper_profile_sync_password' => env('WEBAIDETECTIVE_BASE_API_PASSWORD', env('WEBAIDETECTIVE_BASE_API_TOKEN')),
        'app_key' => env('WEBAIDETECTIVE_BASE_APP_KEY'),
    ],

    'openrouter' => [
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'api_url' => env('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions'),
        'api_key' => env('OPENROUTER_API_KEY'),
        'text_model' => env('OPENROUTER_TEXT_MODEL', 'openai/gpt-4o-mini'),
        'data_model' => env('OPENROUTER_DATA_MODEL', env('OPENROUTER_ANALYSIS_MODEL', 'openai/gpt-4o')),
        'analysis_model' => env('OPENROUTER_ANALYSIS_MODEL', 'openai/gpt-4o'),
        'image_generation_model' => env('OPENROUTER_IMAGE_GENERATION_MODEL', env('OPENROUTER_IMAGE_MODEL', 'openai/gpt-image-1')),
        'image_model' => env('OPENROUTER_IMAGE_MODEL', 'openai/gpt-image-1'),
        'image_understanding_model' => env('OPENROUTER_IMAGE_UNDERSTANDING_MODEL', env('OPENROUTER_VISION_MODEL', 'openai/gpt-4o')),
        'vision_model' => env('OPENROUTER_VISION_MODEL', 'openai/gpt-4o'),
        'speech_to_text_model' => env('OPENROUTER_SPEECH_TO_TEXT_MODEL', 'openai/whisper-1'),
        'text_to_speech_model' => env('OPENROUTER_TEXT_TO_SPEECH_MODEL', 'openai/tts-1'),
        'referer_url' => env('OPENROUTER_REFERER_URL', env('OPENROUTER_SITE_URL', env('APP_URL'))),
        'site_url' => env('OPENROUTER_SITE_URL', env('APP_URL')),
        'model_title' => env('OPENROUTER_MODEL_TITLE', env('OPENROUTER_APP_NAME', env('APP_NAME'))),
        'app_name' => env('OPENROUTER_APP_NAME', env('APP_NAME')),
        'timeout' => env('OPENROUTER_TIMEOUT', 120),
        'image_generation_timeout' => env('OPENROUTER_IMAGE_GENERATION_TIMEOUT', 600),
        'temperature' => env('OPENROUTER_TEMPERATURE', 0.4),
        'max_completion_tokens' => env('OPENROUTER_MAX_COMPLETION_TOKENS', 1500),
        'stream_enabled' => env('OPENROUTER_STREAM_ENABLED', true),
    ],

];
