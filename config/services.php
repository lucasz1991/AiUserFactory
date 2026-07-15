<?php

return [

    'github' => [
        'token' => env('GITHUB_TOKEN'),
    ],

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
        'text_to_speech_model' => env('OPENROUTER_TEXT_TO_SPEECH_MODEL', 'x-ai/grok-voice-tts-1.0'),
        'audio_output_api_url' => env('OPENROUTER_AUDIO_OUTPUT_API_URL', 'https://openrouter.ai/api/v1/audio/speech'),
        'audio_output_voice' => env('OPENROUTER_AUDIO_OUTPUT_VOICE', 'Eve'),
        'audio_output_format' => env('OPENROUTER_AUDIO_OUTPUT_FORMAT', 'mp3'),
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

    'local_assistant_voice' => [
        'enabled' => env('LOCAL_ASSISTANT_VOICE_ENABLED', false),
        'temp_path' => env('LOCAL_ASSISTANT_VOICE_TEMP_PATH', storage_path('app/private/assistant-voice')),
        'lock_wait_seconds' => env('LOCAL_ASSISTANT_VOICE_LOCK_WAIT_SECONDS', 30),
        'ffmpeg' => [
            'binary' => env('LOCAL_ASSISTANT_VOICE_FFMPEG_BINARY', 'ffmpeg'),
            'command' => null,
            'timeout' => env('LOCAL_ASSISTANT_VOICE_FFMPEG_TIMEOUT', 60),
        ],
        'whisper' => [
            'binary' => env('LOCAL_ASSISTANT_WHISPER_BINARY', storage_path('app/voice-runtime/whisper.cpp/build/bin/whisper-cli')),
            'command' => null,
            'model' => env('LOCAL_ASSISTANT_WHISPER_MODEL', storage_path('app/voice-runtime/whisper.cpp/models/ggml-small.bin')),
            'language' => env('LOCAL_ASSISTANT_WHISPER_LANGUAGE', 'de'),
            'threads' => env('LOCAL_ASSISTANT_WHISPER_THREADS', 0),
            'timeout' => env('LOCAL_ASSISTANT_WHISPER_TIMEOUT', 240),
        ],
        'piper' => [
            'binary' => env('LOCAL_ASSISTANT_PIPER_BINARY', storage_path('app/voice-runtime/piper-venv/bin/piper')),
            'command' => null,
            'model' => env('LOCAL_ASSISTANT_PIPER_MODEL', storage_path('app/voice-runtime/piper-voices/de_DE-thorsten-medium.onnx')),
            'config' => env('LOCAL_ASSISTANT_PIPER_CONFIG', storage_path('app/voice-runtime/piper-voices/de_DE-thorsten-medium.onnx.json')),
            'mode' => env('LOCAL_ASSISTANT_PIPER_MODE', 'cli'),
            'timeout' => env('LOCAL_ASSISTANT_PIPER_TIMEOUT', 120),
        ],
        'install' => [
            'php_binary' => env('LOCAL_ASSISTANT_VOICE_INSTALL_PHP_BINARY'),
            'bash_binary' => env('LOCAL_ASSISTANT_VOICE_INSTALL_BASH_BINARY', 'bash'),
            'script' => env('LOCAL_ASSISTANT_VOICE_INSTALL_SCRIPT', base_path('scripts/bootstrap-local-assistant-voice.sh')),
            'state_path' => env('LOCAL_ASSISTANT_VOICE_INSTALL_STATE_PATH', storage_path('app/voice-runtime/install-state.json')),
            'lock_path' => env('LOCAL_ASSISTANT_VOICE_INSTALL_LOCK_PATH', storage_path('app/voice-runtime/install.lock')),
            'log_path' => env('LOCAL_ASSISTANT_VOICE_INSTALL_LOG_PATH', storage_path('logs/local-assistant-voice-install.log')),
            'timeout' => env('LOCAL_ASSISTANT_VOICE_INSTALL_TIMEOUT', 7200),
            'idle_timeout' => env('LOCAL_ASSISTANT_VOICE_INSTALL_IDLE_TIMEOUT', 900),
        ],
    ],

];
