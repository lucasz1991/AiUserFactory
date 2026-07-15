<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Ai\LocalAssistantVoiceException;
use App\Services\Ai\LocalAssistantVoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssistantAudioInputTranscriptionController extends Controller
{
    public function __invoke(Request $request, LocalAssistantVoiceService $localVoice)
    {
        $connectionId = (string) Str::uuid();
        $startedAt = microtime(true);

        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:20480'],
        ]);

        if ($this->assistantSetting('speech_input_provider', 'browser') === 'whisper_local') {
            return $this->transcribeWithLocalWhisper(
                $validated['audio'],
                $localVoice,
                $connectionId,
                $startedAt,
            );
        }

        $serviceUrl = $this->assistantSetting('vosk_transcription_url');

        if ($serviceUrl === '') {
            return response()->json([
                'message' => 'Vosk-Spracheingabe ist nicht konfiguriert. Bitte die Vosk Transcription URL in den AI Chatbot-Einstellungen setzen.',
                'connection_id' => $connectionId,
            ], 422, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        $audio = $validated['audio'];
        $audioContents = $audio->get();

        if ($audioContents === false || $audioContents === '') {
            return response()->json([
                'message' => 'Die Audiodatei fuer die Vosk-Spracheingabe ist leer oder konnte nicht gelesen werden.',
                'connection_id' => $connectionId,
            ], 422, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        try {
            $providerResponse = Http::acceptJson()
                ->connectTimeout(10)
                ->timeout(120)
                ->attach(
                    'audio',
                    $audioContents,
                    $audio->getClientOriginalName() ?: 'workflow-copilot-audio.webm',
                    ['Content-Type' => $audio->getMimeType() ?: 'application/octet-stream'],
                )
                ->post($serviceUrl, [
                    'language' => 'de-DE',
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Assistant Vosk STT transport failed.', [
                'connection_id' => $connectionId,
                'error' => $exception->getMessage(),
                'service_url' => $serviceUrl,
            ]);

            return response()->json([
                'message' => 'Die Vosk-Spracheingabe konnte nicht gestartet werden: '.$exception->getMessage(),
                'connection_id' => $connectionId,
            ], 503, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        if (! $providerResponse->successful()) {
            $providerError = $this->providerErrorMessage((string) $providerResponse->body(), $providerResponse->status());

            Log::warning('Assistant Vosk STT request rejected.', [
                'connection_id' => $connectionId,
                'status' => $providerResponse->status(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => Str::limit($providerError, 500, ''),
            ]);

            return response()->json([
                'message' => 'Vosk Spracheingabe antwortet mit HTTP '.$providerResponse->status().'.',
                'detail' => $providerError,
                'provider_status' => $providerResponse->status(),
                'connection_id' => $connectionId,
            ], 424, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        $text = $this->transcriptFromResponse($providerResponse->json());

        if ($text === '') {
            return response()->json([
                'message' => 'Vosk hat keinen erkannten Text zurueckgegeben.',
                'connection_id' => $connectionId,
            ], 422, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        return response()->json([
            'text' => Str::limit($text, 8000, ''),
        ], 200, [
            'X-AI-Connection-ID' => $connectionId,
        ]);
    }

    private function transcribeWithLocalWhisper(
        UploadedFile $audio,
        LocalAssistantVoiceService $localVoice,
        string $connectionId,
        float $startedAt,
    ) {
        try {
            $text = $localVoice->transcribe($audio, $connectionId);
        } catch (LocalAssistantVoiceException $exception) {
            Log::warning('Assistant local Whisper STT failed.', [
                'connection_id' => $connectionId,
                'reason_code' => $exception->reasonCode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
                'reason_code' => $exception->reasonCode,
                'connection_id' => $connectionId,
            ], 503, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Assistant local Whisper STT failed unexpectedly.', [
                'connection_id' => $connectionId,
                'error' => $exception->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json([
                'message' => 'Die serverlokale Whisper-Spracheingabe ist unerwartet fehlgeschlagen.',
                'reason_code' => 'whisper_unexpected_error',
                'connection_id' => $connectionId,
            ], 503, [
                'X-AI-Connection-ID' => $connectionId,
            ]);
        }

        return response()->json([
            'text' => Str::limit($text, 8000, ''),
        ], 200, [
            'X-AI-Connection-ID' => $connectionId,
            'X-AI-Speech-Provider' => 'whisper_local',
        ]);
    }

    private function transcriptFromResponse(mixed $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $text = data_get($payload, 'text')
            ?? data_get($payload, 'transcript')
            ?? data_get($payload, 'result.text');

        return trim((string) ($text ?? ''));
    }

    private function providerErrorMessage(string $body, int $status = 0): string
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
