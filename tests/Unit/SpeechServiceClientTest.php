<?php

namespace Tests\Unit;

use App\Services\Ai\SpeechServiceClient;
use App\Services\Ai\SpeechServiceException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpeechServiceClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.speech_service.enabled' => true,
            'services.speech_service.url' => 'http://127.0.0.1:8092',
            'services.speech_service.client_id' => 'followflow',
            'services.speech_service.token' => 'test-speech-token',
            'services.speech_service.token_file' => '',
            'services.speech_service.connect_timeout' => 2,
            'services.speech_service.stt_timeout' => 10,
            'services.speech_service.tts_timeout' => 10,
            'services.local_assistant_voice.whisper.language' => 'de',
        ]);
    }

    public function test_status_uses_authenticated_versioned_loopback_endpoint(): void
    {
        Http::fake([
            'http://127.0.0.1:8092/v1/status' => Http::response([
                'status' => 'ready',
                'engines' => [
                    'ffmpeg' => 'ready',
                    'whisper' => 'ready',
                    'piper' => 'ready',
                ],
            ]),
        ]);

        $status = app(SpeechServiceClient::class)->status();

        $this->assertSame('ready', $status['status']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'http://127.0.0.1:8092/v1/status'
            && $request->hasHeader('Authorization', 'Bearer test-speech-token')
            && $request->hasHeader('X-Client-ID', 'followflow'));
    }

    public function test_transcription_uses_the_exact_base64_json_contract(): void
    {
        Http::fake([
            'http://127.0.0.1:8092/v1/transcriptions' => Http::response([
                'text' => 'Hallo Followflow',
                'request_id' => 'speech-request-1',
            ]),
        ]);

        $audio = UploadedFile::fake()->createWithContent('sprach eingabe.webm', 'fake-webm-audio');
        $expectedMimeType = 'audio/webm';
        $text = app(SpeechServiceClient::class)->transcribe($audio);

        $this->assertSame('Hallo Followflow', $text);
        Http::assertSent(function ($request) use ($expectedMimeType): bool {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'http://127.0.0.1:8092/v1/transcriptions'
                && $request->hasHeader('Authorization', 'Bearer test-speech-token')
                && $request->hasHeader('X-Client-ID', 'followflow')
                && array_keys($payload) === ['audio_base64', 'filename', 'mime_type', 'language']
                && base64_decode((string) $payload['audio_base64'], true) === 'fake-webm-audio'
                && $payload['filename'] === 'sprach-eingabe.webm'
                && $payload['mime_type'] === $expectedMimeType
                && $payload['language'] === 'de';
        });
    }

    public function test_synthesis_uses_text_and_clamped_speed_and_requires_wav(): void
    {
        $wave = 'RIFF'.pack('V', 40).'WAVE'.str_repeat("\0", 32);
        Http::fake([
            'http://127.0.0.1:8092/v1/speech' => Http::response($wave, 200, [
                'Content-Type' => 'audio/wav',
            ]),
        ]);

        $audio = app(SpeechServiceClient::class)->synthesize('  Hallo   Welt  ', 3.5);

        $this->assertSame($wave, $audio);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://127.0.0.1:8092/v1/speech'
                && $request->data() === [
                    'text' => 'Hallo Welt',
                    'speed' => 2.0,
                ];
        });
    }

    public function test_non_loopback_service_url_is_rejected_before_any_request(): void
    {
        config(['services.speech_service.url' => 'https://speech.example.test']);
        Http::fake();

        try {
            app(SpeechServiceClient::class)->status();
            $this->fail('An unsafe service URL should have been rejected.');
        } catch (SpeechServiceException $exception) {
            $this->assertSame('unsafe_service_url', $exception->reasonCode);
        }

        Http::assertNothingSent();
    }

    public function test_redirects_are_rejected_without_following_them(): void
    {
        Http::fake([
            'http://127.0.0.1:8092/v1/status' => Http::response('', 302, [
                'Location' => 'https://speech.example.test/v1/status',
            ]),
        ]);

        try {
            app(SpeechServiceClient::class)->status();
            $this->fail('A redirect should have been rejected.');
        } catch (SpeechServiceException $exception) {
            $this->assertSame('redirect_forbidden', $exception->reasonCode);
            $this->assertSame(302, $exception->providerStatus);
        }

        Http::assertSentCount(1);
    }

    public function test_token_can_be_read_from_a_server_side_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'followflow-speech-token-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'token-from-file');

        try {
            config([
                'services.speech_service.token' => '',
                'services.speech_service.token_file' => $path,
            ]);
            Http::fake([
                'http://127.0.0.1:8092/v1/status' => Http::response(['ready' => true]),
            ]);

            app(SpeechServiceClient::class)->status();

            Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer token-from-file'));
        } finally {
            @unlink($path);
        }
    }

    public function test_control_characters_in_token_are_rejected_before_any_request(): void
    {
        config(['services.speech_service.token' => "token\r\nX-Injected: yes"]);
        Http::fake();

        try {
            app(SpeechServiceClient::class)->status();
            $this->fail('A token containing control characters should have been rejected.');
        } catch (SpeechServiceException $exception) {
            $this->assertSame('invalid_token', $exception->reasonCode);
        }

        Http::assertNothingSent();
    }

    public function test_invalid_audio_response_is_rejected(): void
    {
        Http::fake([
            'http://127.0.0.1:8092/v1/speech' => Http::response('<html>error</html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $this->expectException(SpeechServiceException::class);
        $this->expectExceptionMessage('keine gueltige WAV-Datei');

        app(SpeechServiceClient::class)->synthesize('Hallo');
    }

    public function test_status_command_reports_only_readiness_fields(): void
    {
        Http::fake([
            'http://127.0.0.1:8092/v1/status' => Http::response([
                'status' => 'ready',
                'engines' => [
                    'ffmpeg' => 'ready',
                    'whisper' => 'ready',
                    'piper' => 'ready',
                ],
                'private_model_path' => '/must/not/be/printed',
            ]),
        ]);

        $this->artisan('assistant:speech-service:status')
            ->expectsOutputToContain('Gemeinsamer Speech-Dienst: bereit')
            ->doesntExpectOutputToContain('/must/not/be/printed')
            ->assertExitCode(0);
    }
}
