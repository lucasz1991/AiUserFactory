<?php

namespace Tests\Feature;

use App\Livewire\Tools\Chatbot;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\LocalAssistantVoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class SharedSpeechServiceGatewayTest extends TestCase
{
    use RefreshDatabase;

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
        ]);
    }

    public function test_shared_service_transcribes_through_the_authenticated_server_gateway(): void
    {
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_input_provider' => 'browser',
        ]);
        Http::fake([
            'http://127.0.0.1:8092/v1/transcriptions' => Http::response([
                'text' => 'Gemeinsam erkannt',
                'request_id' => 'stt-1',
            ]),
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('assistant.audio-input.transcribe'), [
                'audio' => UploadedFile::fake()->createWithContent('speech.webm', 'fake-webm-audio'),
            ]);

        $response
            ->assertOk()
            ->assertHeader('X-AI-Speech-Provider', 'shared_speech_service')
            ->assertJsonPath('text', 'Gemeinsam erkannt');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-speech-token')
            && $request->hasHeader('X-Client-ID', 'followflow')
            && ! array_key_exists('token', $request->data()));
    }

    public function test_shared_service_forces_recorded_chatbot_input_for_the_default_browser_provider(): void
    {
        $chatbot = Livewire::test(Chatbot::class)
            ->assertSet('assistantSpeechInputProvider', 'browser');

        $this->assertStringContainsString(
            'sharedSpeechServiceEnabled: true',
            html_entity_decode($chatbot->html(), ENT_QUOTES | ENT_HTML5),
        );
    }

    public function test_shared_service_overrides_a_stored_browser_provider_for_chatbot_recording(): void
    {
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_input_provider' => 'browser',
        ]);

        $chatbot = Livewire::test(Chatbot::class)
            ->assertSet('assistantSpeechInputProvider', 'browser');

        $this->assertStringContainsString(
            'sharedSpeechServiceEnabled: true',
            html_entity_decode($chatbot->html(), ENT_QUOTES | ENT_HTML5),
        );
    }

    public function test_disabled_shared_service_keeps_the_stored_browser_provider(): void
    {
        config(['services.speech_service.enabled' => false]);
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_input_provider' => 'browser',
        ]);

        $chatbot = Livewire::test(Chatbot::class)
            ->assertSet('assistantSpeechInputProvider', 'browser');

        $this->assertStringContainsString(
            'sharedSpeechServiceEnabled: false',
            html_entity_decode($chatbot->html(), ENT_QUOTES | ENT_HTML5),
        );
    }

    public function test_shared_service_returns_wav_through_the_authenticated_server_gateway(): void
    {
        $wave = 'RIFF'.pack('V', 40).'WAVE'.str_repeat("\0", 32);
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_output_provider' => 'ai',
        ]);
        Http::fake([
            'http://127.0.0.1:8092/v1/speech' => Http::response($wave, 200, [
                'Content-Type' => 'audio/wav',
            ]),
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('assistant.audio-output.stream'), [
                'text' => 'Gemeinsam sprechen',
                'speed' => 1.25,
            ]);

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertHeader('X-AI-Speech-Provider', 'shared_speech_service');
        $this->assertSame($wave, $response->getContent());

        Http::assertSent(fn ($request): bool => $request->data() === [
            'text' => 'Gemeinsam sprechen',
            'speed' => 1.25,
        ]);
    }

    public function test_enabled_shared_service_fails_closed_without_local_piper_fallback(): void
    {
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_output_provider' => 'piper_local',
        ]);
        $this->mock(LocalAssistantVoiceService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('synthesize');
        });
        Http::fake([
            'http://127.0.0.1:8092/v1/speech' => Http::response([
                'error' => ['code' => 'busy'],
            ], 503),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('assistant.audio-output.stream'), [
                'text' => 'Kein Fallback',
            ])
            ->assertStatus(503)
            ->assertJsonPath('reason_code', 'service_synthesis_failed')
            ->assertJsonMissing(['detail' => 'busy']);

        Http::assertSentCount(1);
    }

    public function test_enabled_shared_service_fails_closed_without_local_whisper_fallback(): void
    {
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_input_provider' => 'whisper_local',
        ]);
        $this->mock(LocalAssistantVoiceService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('transcribe');
        });
        Http::fake([
            'http://127.0.0.1:8092/v1/transcriptions' => Http::response([
                'error' => ['code' => 'busy'],
            ], 503),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('assistant.audio-input.transcribe'), [
                'audio' => UploadedFile::fake()->createWithContent('speech.webm', 'fake-webm-audio'),
            ])
            ->assertStatus(503)
            ->assertJsonPath('reason_code', 'service_transcription_failed')
            ->assertJsonMissing(['detail' => 'busy']);

        Http::assertSentCount(1);
    }

    public function test_audio_routes_use_separate_named_rate_limiters(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertContains(
            'throttle:assistant-stt',
            $routes->getByName('assistant.audio-input.transcribe')->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:assistant-tts',
            $routes->getByName('assistant.audio-output.stream')->gatherMiddleware(),
        );
    }

    public function test_unauthenticated_requests_never_reach_the_shared_service(): void
    {
        Http::fake();

        $this->post(route('assistant.audio-input.transcribe'), [
            'audio' => UploadedFile::fake()->createWithContent('speech.webm', 'fake-webm-audio'),
        ])->assertRedirect();

        Http::assertNothingSent();
    }
}
