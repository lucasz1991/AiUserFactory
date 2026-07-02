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
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
            'voice' => ['nullable', 'string', 'max:80'],
            'format' => ['nullable', 'string', 'in:mp3,wav,opus,pcm'],
            'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2'],
        ]);

        $settings = Setting::getValue('services', 'openrouter');
        $settings = is_array($settings) ? $settings : [];

        $apiKey = trim((string) ($settings['api_key'] ?? config('services.openrouter.api_key')));

        if ($apiKey === '') {
            return response()->json([
                'message' => 'OpenRouter API Key fehlt. Bitte in den Einstellungen speichern.',
            ], 422);
        }

        $apiUrl = trim((string) ($settings['audio_output_api_url'] ?? config('services.openrouter.audio_output_api_url', 'https://openrouter.ai/api/v1/audio/speech')));
        $model = trim((string) (
            $settings['audio_output_model']
            ?? $settings['text_to_speech_model']
            ?? config('services.openrouter.text_to_speech_model', 'openai/tts-1')
        ));
        $voice = trim((string) ($validated['voice'] ?? $settings['audio_output_voice'] ?? config('services.openrouter.audio_output_voice', 'alloy'))) ?: 'alloy';
        $format = trim((string) ($validated['format'] ?? $settings['audio_output_format'] ?? config('services.openrouter.audio_output_format', 'mp3'))) ?: 'mp3';
        $contentType = $this->contentType($format);

        try {
            $response = Http::timeout(90)
                ->withToken($apiKey)
                ->accept($contentType)
                ->asJson()
                ->withHeaders(array_filter([
                    'HTTP-Referer' => trim((string) ($settings['referer_url'] ?? $settings['site_url'] ?? config('services.openrouter.referer_url'))),
                    'X-Title' => trim((string) ($settings['model_title'] ?? $settings['app_name'] ?? config('services.openrouter.model_title'))),
                ]))
                ->post($apiUrl, [
                    'model' => $model,
                    'input' => trim($validated['text']),
                    'voice' => $voice,
                    'response_format' => $format,
                    'speed' => (float) ($validated['speed'] ?? 1),
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Assistant audio output transport failed.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Audioausgabe konnte nicht erreicht werden: '.$exception->getMessage(),
            ], 502);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Audioausgabe fehlgeschlagen: HTTP '.$response->status(),
                'detail' => Str::limit($response->body(), 1000, ''),
            ], $response->status());
        }

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function contentType(string $format): string
    {
        return match ($format) {
            'wav' => 'audio/wav',
            'opus' => 'audio/opus',
            'pcm' => 'audio/L16',
            default => 'audio/mpeg',
        };
    }
}
