<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssistantAudioOutputStreamController extends Controller
{
    private const OPENROUTER_AUDIO_SPEECH_URL = 'https://openrouter.ai/api/v1/audio/speech';

    public function __invoke(Request $request)
    {
        $connectionId = (string) Str::uuid();
        $startedAt = microtime(true);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
            'voice' => ['nullable', 'string', 'max:80'],
            'format' => ['nullable', 'string', 'in:mp3,wav,opus,pcm'],
            'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2'],
        ]);

        if ($this->assistantSetting('speech_output_provider', 'ai') === 'espeak_ng') {
            return $this->streamEspeakNgAudio($validated, $connectionId, $startedAt);
        }

        $apiKey = $this->setting('api_key', (string) config('services.openrouter.api_key'));
        $apiUrl = $this->openRouterAudioOutputApiUrl();
        $model = $this->providerModel($this->setting('audio_output_model')
            ?: $this->setting('text_to_speech_model', (string) config('services.openrouter.text_to_speech_model', 'x-ai/grok-voice-tts-1.0')));
        $voice = $this->providerVoice($model, trim((string) ($validated['voice'] ?? $this->setting('audio_output_voice'))));
        $format = $this->providerAudioFormat($model, trim((string) ($validated['format'] ?? $this->setting('audio_output_format', 'mp3'))) ?: 'mp3');
        $speed = (float) ($validated['speed'] ?? 1);

        if ($apiKey === '' || $model === '' || $apiUrl === '') {
            return response()->json([
                'message' => 'OpenRouter-Audioausgabe ist nicht vollstaendig konfiguriert. Bitte API-Key, TTS-Modell und Audio-Endpoint pruefen.',
                'connection_id' => $connectionId,
            ], 422, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        if (! $this->isOpenRouterSpeechUrl($apiUrl)) {
            return response()->json([
                'message' => 'Der konfigurierte Audio-Endpoint ist kein OpenRouter Speech-Endpoint.',
                'configured_url' => $apiUrl,
                'expected_default' => self::OPENROUTER_AUDIO_SPEECH_URL,
                'connection_id' => $connectionId,
            ], 422, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        $providerContentType = match ($format) {
            'opus' => 'audio/opus',
            'wav' => 'audio/wav',
            'pcm' => 'audio/pcm',
            default => 'audio/mpeg',
        };
        $responseContentType = $format === 'pcm' ? 'audio/wav' : $providerContentType;

        try {
            $providerResponse = Http::withToken($apiKey)
                ->accept($providerContentType)
                ->asJson()
                ->withHeaders(array_filter([
                    'HTTP-Referer' => $this->setting('referer_url', (string) config('app.url')),
                    'X-Title' => $this->setting('model_title', (string) config('app.name')),
                ]))
                ->withoutRedirecting()
                ->connectTimeout(10)
                ->timeout(60)
                ->withOptions([
                    'stream' => $format !== 'pcm',
                    'http_errors' => false,
                ])
                ->post($apiUrl, array_filter([
                    'model' => $model,
                    'input' => trim((string) $validated['text']),
                    'voice' => $voice,
                    'response_format' => $format,
                    'speed' => $speed,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (\Throwable $exception) {
            Log::warning('Assistant OpenRouter TTS transport failed.', [
                'connection_id' => $connectionId,
                'error' => $exception->getMessage(),
                'model' => $model,
                'api_url' => $apiUrl,
            ]);

            return response()->json([
                'message' => 'Die OpenRouter-Audioausgabe konnte nicht gestartet werden: '.$exception->getMessage(),
                'connection_id' => $connectionId,
            ], 503, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        if ($providerResponse->redirect()) {
            return response()->json([
                'message' => 'Der OpenRouter-Audio-Endpoint leitet weiter. Bitte die finale OpenRouter-TTS-URL direkt konfigurieren.',
                'location' => (string) $providerResponse->header('Location'),
                'connection_id' => $connectionId,
            ], 422, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        if (! $providerResponse->successful()) {
            $errorBody = (string) $providerResponse->toPsrResponse()->getBody();
            $providerError = $this->providerErrorMessage($errorBody, $providerResponse->status(), $model);

            Log::warning('Assistant OpenRouter TTS request rejected.', [
                'connection_id' => $connectionId,
                'status' => $providerResponse->status(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => Str::limit($providerError, 500, ''),
                'model' => $model,
                'voice' => $voice,
                'format' => $format,
            ]);

            return response()->json([
                'message' => 'OpenRouter Audio/TTS antwortet mit HTTP '.$providerResponse->status().'.',
                'detail' => $providerError,
                'provider_status' => $providerResponse->status(),
                'connection_id' => $connectionId,
            ], 424, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        $body = $providerResponse->toPsrResponse()->getBody();

        if ($format === 'pcm') {
            $pcm = (string) $body;
            $audio = $this->pcmToWav($pcm);

            return response($audio, 200, [
                'Content-Type' => $responseContentType,
                'Content-Length' => (string) strlen($audio),
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(8192);

                if (function_exists('ob_flush')) {
                    @ob_flush();
                }

                flush();
            }
        }, 200, [
            'Content-Type' => $responseContentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
            'X-AI-Connection-ID' => $connectionId,
        ]);
    }

    private function streamEspeakNgAudio(array $validated, string $connectionId, float $startedAt)
    {
        $serviceUrl = $this->assistantSetting('espeak_ng_speech_url');
        $voice = trim((string) ($validated['voice'] ?? $this->assistantSetting('espeak_ng_voice', 'de'))) ?: 'de';
        $speed = (float) ($validated['speed'] ?? 1);

        if ($serviceUrl === '') {
            return response()->json([
                'message' => 'eSpeak NG-Sprachausgabe ist nicht konfiguriert. Bitte die eSpeak NG Speech URL in den AI Chatbot-Einstellungen setzen.',
                'connection_id' => $connectionId,
            ], 422, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        try {
            $providerResponse = Http::withHeaders([
                'Accept' => 'audio/wav, audio/*, application/json',
            ])
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout(10)
                ->timeout(60)
                ->withOptions([
                    'stream' => true,
                    'http_errors' => false,
                ])
                ->post($serviceUrl, [
                    'text' => trim((string) $validated['text']),
                    'voice' => $voice,
                    'speed' => $speed,
                    'format' => 'wav',
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Assistant eSpeak NG TTS transport failed.', [
                'connection_id' => $connectionId,
                'error' => $exception->getMessage(),
                'service_url' => $serviceUrl,
            ]);

            return response()->json([
                'message' => 'Die eSpeak NG-Sprachausgabe konnte nicht gestartet werden: '.$exception->getMessage(),
                'connection_id' => $connectionId,
            ], 503, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        if ($providerResponse->redirect()) {
            return response()->json([
                'message' => 'Der eSpeak NG-Audio-Endpoint leitet weiter. Bitte die finale Speech-URL direkt konfigurieren.',
                'location' => (string) $providerResponse->header('Location'),
                'connection_id' => $connectionId,
            ], 422, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        if (! $providerResponse->successful()) {
            $errorBody = (string) $providerResponse->toPsrResponse()->getBody();
            $providerError = $this->providerServiceErrorMessage($errorBody, $providerResponse->status());

            Log::warning('Assistant eSpeak NG TTS request rejected.', [
                'connection_id' => $connectionId,
                'status' => $providerResponse->status(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => Str::limit($providerError, 500, ''),
                'voice' => $voice,
            ]);

            return response()->json([
                'message' => 'eSpeak NG Audio/TTS antwortet mit HTTP '.$providerResponse->status().'.',
                'detail' => $providerError,
                'provider_status' => $providerResponse->status(),
                'connection_id' => $connectionId,
            ], 424, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        $body = $providerResponse->toPsrResponse()->getBody();
        $contentType = trim((string) $providerResponse->header('Content-Type')) ?: 'audio/wav';

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(8192);

                if (function_exists('ob_flush')) {
                    @ob_flush();
                }

                flush();
            }
        }, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
            'X-AI-Connection-ID' => $connectionId,
        ]);
    }

    private function openRouterAudioOutputApiUrl(): string
    {
        $explicit = $this->setting('audio_output_api_url', (string) config('services.openrouter.audio_output_api_url', ''));

        if ($explicit !== '') {
            return $explicit;
        }

        $chatApiUrl = $this->setting('api_url', (string) config('services.openrouter.api_url', ''));

        if ($chatApiUrl !== '' && $this->isOpenRouterUrl($chatApiUrl) && Str::contains($chatApiUrl, '/chat/completions')) {
            return Str::replace('/chat/completions', '/audio/speech', $chatApiUrl);
        }

        return self::OPENROUTER_AUDIO_SPEECH_URL;
    }

    private function providerErrorMessage(string $body, int $status = 0, string $model = ''): string
    {
        $payload = json_decode($body, true);
        $message = is_array($payload) ? data_get($payload, 'error.message') : null;

        if ($status === 404 || (is_string($message) && str_contains(Str::lower($message), 'provider returned 404'))) {
            return 'Das konfigurierte TTS-Modell "'.$model.'" wurde am OpenRouter Speech-Endpoint nicht gefunden oder unterstuetzt keine Speech-Ausgabe. Verwende ein Speech-Modell wie "x-ai/grok-voice-tts-1.0" mit einer Stimme wie "Eve".';
        }

        if (is_string($message) && str_contains($message, 'specify "prompt" or "messages"')) {
            return 'Der konfigurierte Audio-Endpoint ist kein TTS-Endpoint und erwartet Chat-Nachrichten. Verwende https://openrouter.ai/api/v1/audio/speech und ein Speech/TTS-Modell.';
        }

        if (is_string($message) && $message !== '') {
            return Str::limit($message, 1000, '');
        }

        return Str::limit($body, 1000, '');
    }

    private function providerServiceErrorMessage(string $body, int $status = 0): string
    {
        $payload = json_decode($body, true);
        $message = is_array($payload)
            ? (data_get($payload, 'error.message') ?? data_get($payload, 'detail') ?? data_get($payload, 'message'))
            : null;

        if (is_string($message) && $message !== '') {
            return Str::limit($message, 1000, '');
        }

        return Str::limit($body !== '' ? $body : 'HTTP '.$status, 1000, '');
    }

    private function providerVoice(string $model, string $voice): string
    {
        $voice = trim($voice);

        if ($voice === '') {
            return $this->defaultVoiceForModel($model);
        }

        if ($this->isGeminiTtsModel($model) && in_array(Str::lower($voice), ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer', 'eve'], true)) {
            return 'Kore';
        }

        if (Str::startsWith(Str::lower($model), 'x-ai/') && in_array(Str::lower($voice), ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer', 'kore'], true)) {
            return 'Eve';
        }

        return $voice;
    }

    private function providerModel(string $model): string
    {
        $model = trim($model);

        return in_array(Str::lower($model), ['openai/tts-1', 'openai/tts-1-hd'], true)
            ? (string) config('services.openrouter.text_to_speech_model', 'x-ai/grok-voice-tts-1.0')
            : $model;
    }

    private function defaultVoiceForModel(string $model): string
    {
        $normalizedModel = Str::lower($model);

        if ($this->isGeminiTtsModel($normalizedModel)) {
            return 'Kore';
        }

        return Str::startsWith($normalizedModel, 'x-ai/') ? 'Eve' : 'alloy';
    }

    private function providerAudioFormat(string $model, string $format): string
    {
        return $this->isGeminiTtsModel($model) ? 'pcm' : $format;
    }

    private function isGeminiTtsModel(string $model): bool
    {
        $normalizedModel = Str::lower($model);

        return Str::startsWith($normalizedModel, 'google/') && Str::contains($normalizedModel, 'tts');
    }

    private function isOpenRouterUrl(string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'openrouter.ai' || Str::endsWith($host, '.openrouter.ai');
    }

    private function isOpenRouterSpeechUrl(string $url): bool
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $this->isOpenRouterUrl($url) && $path === '/api/v1/audio/speech';
    }

    private function pcmToWav(string $pcm, int $sampleRate = 24000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $dataLength = strlen($pcm);
        $byteRate = $sampleRate * $channels * intdiv($bitsPerSample, 8);
        $blockAlign = $channels * intdiv($bitsPerSample, 8);

        return 'RIFF'
            .pack('V', 36 + $dataLength)
            .'WAVEfmt '
            .pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
            .'data'
            .pack('V', $dataLength)
            .$pcm;
    }

    private function setting(string $key, string $default = ''): string
    {
        $settings = Setting::getValue('services', 'openrouter');
        $settings = is_array($settings) ? $settings : [];
        $value = $settings[$key] ?? config("services.openrouter.{$key}", $default);

        if (is_array($value)) {
            $value = collect($value)->flatten()->first();
        }

        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : $default;
    }

    private function assistantSetting(string $key, string $default = ''): string
    {
        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');
        $settings = is_array($settings) ? $settings : [];
        $value = $settings[$key] ?? $default;

        if (is_array($value)) {
            $value = collect($value)->flatten()->first();
        }

        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : $default;
    }
}
