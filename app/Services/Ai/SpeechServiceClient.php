<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SpeechServiceClient
{
    private const MAX_AUDIO_RESPONSE_BYTES = 32 * 1024 * 1024;

    public function enabled(): bool
    {
        return (bool) config('services.speech_service.enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $response = $this->send(
            fn (): Response => $this->request(
                timeout: max(1, (int) config('services.speech_service.connect_timeout', 5) + 5),
                accept: 'application/json',
            )->get($this->endpoint('/v1/status')),
        );

        $this->assertSuccessful($response, 'status');
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new SpeechServiceException(
                'Der gemeinsame Speech-Dienst hat einen ungueltigen Status geliefert.',
                'invalid_status_response',
            );
        }

        return $payload;
    }

    public function transcribe(UploadedFile $audio): string
    {
        $contents = $audio->get();

        if ($contents === false || $contents === '') {
            throw new SpeechServiceException(
                'Die Audiodatei ist leer oder konnte nicht gelesen werden.',
                'empty_audio',
            );
        }

        $response = $this->send(
            fn (): Response => $this->request(
                timeout: max(1, (int) config('services.speech_service.stt_timeout', 300)),
                accept: 'application/json',
            )->post($this->endpoint('/v1/transcriptions'), [
                'audio_base64' => base64_encode($contents),
                'filename' => $this->safeFilename($audio),
                'mime_type' => $this->audioMimeType($audio),
                'language' => trim((string) config('services.local_assistant_voice.whisper.language', 'de')) ?: 'de',
            ]),
        );

        $this->assertSuccessful($response, 'transcription');
        $text = trim((string) $response->json('text', ''));

        if ($text === '') {
            throw new SpeechServiceException(
                'Der gemeinsame Speech-Dienst hat keinen erkannten Text geliefert.',
                'empty_transcript',
            );
        }

        return $text;
    }

    public function synthesize(string $text, float $speed = 1.0): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            throw new SpeechServiceException(
                'Fuer die Sprachausgabe wurde kein Text uebergeben.',
                'empty_text',
            );
        }

        $response = $this->send(
            fn (): Response => $this->request(
                timeout: max(1, (int) config('services.speech_service.tts_timeout', 180)),
                accept: 'audio/wav',
            )->post($this->endpoint('/v1/speech'), [
                'text' => $text,
                'speed' => max(0.5, min(2.0, $speed)),
            ]),
        );

        $this->assertSuccessful($response, 'synthesis');
        $contentType = Str::lower(trim((string) $response->header('Content-Type')));
        $audio = $response->body();

        if (! Str::startsWith($contentType, 'audio/wav')
            || strlen($audio) < 12
            || strlen($audio) > self::MAX_AUDIO_RESPONSE_BYTES
            || ! str_starts_with($audio, 'RIFF')
            || substr($audio, 8, 4) !== 'WAVE') {
            throw new SpeechServiceException(
                'Der gemeinsame Speech-Dienst hat keine gueltige WAV-Datei geliefert.',
                'invalid_audio_response',
            );
        }

        return $audio;
    }

    private function request(int $timeout, string $accept): PendingRequest
    {
        if (! $this->enabled()) {
            throw new SpeechServiceException(
                'Der gemeinsame Speech-Dienst ist deaktiviert.',
                'service_disabled',
            );
        }

        return Http::withToken($this->token())
            ->withHeaders([
                'X-Client-ID' => $this->clientId(),
            ])
            ->accept($accept)
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout(max(1, (int) config('services.speech_service.connect_timeout', 5)))
            ->timeout($timeout)
            ->withOptions([
                'http_errors' => false,
                'proxy' => '',
            ]);
    }

    /**
     * @param  callable(): Response  $callback
     */
    private function send(callable $callback): Response
    {
        try {
            return $callback();
        } catch (SpeechServiceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SpeechServiceException(
                'Der gemeinsame Speech-Dienst ist nicht erreichbar.',
                'service_unreachable',
                previous: $exception,
            );
        }
    }

    private function endpoint(string $path): string
    {
        $url = trim((string) config('services.speech_service.url', 'http://127.0.0.1:8092'));
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! in_array(Str::lower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! $this->isLoopbackHost((string) ($parts['host'] ?? ''))) {
            throw new SpeechServiceException(
                'Die Speech-Service-URL muss auf einen lokalen Loopback-Endpunkt zeigen.',
                'unsafe_service_url',
            );
        }

        $port = $parts['port'] ?? null;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new SpeechServiceException(
                'Die Speech-Service-URL enthaelt einen ungueltigen Port.',
                'unsafe_service_url',
            );
        }

        return rtrim($url, '/').'/'.ltrim($path, '/');
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = Str::lower(rtrim(trim($host), '.'));

        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($host, '127.');
    }

    private function token(): string
    {
        $token = trim((string) config('services.speech_service.token', ''));

        if ($token !== '') {
            return $this->validatedToken($token);
        }

        $tokenFile = trim((string) config('services.speech_service.token_file', ''));

        if ($tokenFile === '' || ! is_file($tokenFile) || ! is_readable($tokenFile)) {
            throw new SpeechServiceException(
                'Fuer den gemeinsamen Speech-Dienst ist kein Zugriffstoken konfiguriert.',
                'missing_token',
            );
        }

        try {
            $contents = file_get_contents($tokenFile);
        } catch (Throwable $exception) {
            throw new SpeechServiceException(
                'Die Token-Datei des gemeinsamen Speech-Dienstes konnte nicht gelesen werden.',
                'token_file_unreadable',
                previous: $exception,
            );
        }

        $token = trim((string) $contents);

        if ($token === '') {
            throw new SpeechServiceException(
                'Die Token-Datei des gemeinsamen Speech-Dienstes ist leer.',
                'missing_token',
            );
        }

        return $this->validatedToken($token);
    }

    private function validatedToken(string $token): string
    {
        if (strlen($token) > 512 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new SpeechServiceException(
                'Das Zugriffstoken des gemeinsamen Speech-Dienstes ist ungueltig.',
                'invalid_token',
            );
        }

        return $token;
    }

    private function clientId(): string
    {
        $clientId = trim((string) config('services.speech_service.client_id', 'followflow'));

        if ($clientId === '' || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/', $clientId) !== 1) {
            throw new SpeechServiceException(
                'Die Client-ID des gemeinsamen Speech-Dienstes ist ungueltig.',
                'invalid_client_id',
            );
        }

        return $clientId;
    }

    private function assertSuccessful(Response $response, string $operation): void
    {
        if ($response->redirect()) {
            throw new SpeechServiceException(
                'Weiterleitungen des gemeinsamen Speech-Dienstes werden nicht akzeptiert.',
                'redirect_forbidden',
                $response->status(),
            );
        }

        if (! $response->successful()) {
            $reason = match ($response->status()) {
                401, 403 => 'service_authentication_failed',
                413 => 'service_payload_too_large',
                429 => 'service_rate_limited',
                default => 'service_'.$operation.'_failed',
            };

            throw new SpeechServiceException(
                'Der gemeinsame Speech-Dienst konnte die Anfrage nicht verarbeiten.',
                $reason,
                $response->status(),
            );
        }
    }

    private function safeFilename(UploadedFile $audio): string
    {
        $filename = basename(trim((string) $audio->getClientOriginalName()));
        $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $filename) ?? '';
        $filename = trim($filename, '.-');

        return Str::limit($filename !== '' ? $filename : 'assistant-audio.webm', 120, '');
    }

    private function audioMimeType(UploadedFile $audio): string
    {
        $supported = [
            'audio/webm',
            'audio/ogg',
            'audio/wav',
            'audio/x-wav',
            'audio/wave',
            'audio/mpeg',
            'audio/mp4',
            'audio/x-m4a',
        ];

        foreach ([$audio->getClientMimeType(), $audio->getMimeType()] as $mimeType) {
            $mimeType = trim((string) $mimeType);
            $baseType = Str::lower(trim(Str::before($mimeType, ';')));

            if (in_array($baseType, $supported, true)) {
                return $mimeType;
            }
        }

        return match (Str::lower($audio->getClientOriginalExtension())) {
            'webm' => 'audio/webm',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'mp4', 'm4a' => 'audio/mp4',
            default => 'application/octet-stream',
        };
    }
}
