<?php

namespace Tests\Feature;

use App\Livewire\Admin\Config\SettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\LocalAssistantVoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class AssistantSpeechProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_speech_provider_settings_are_saved(): void
    {
        Livewire::test(SettingsPage::class, ['tab' => 'assistant'])
            ->set('assistantSpeechInputProvider', 'vosk')
            ->set('assistantVoskTranscriptionUrl', 'http://127.0.0.1:2700/transcribe')
            ->set('assistantSpeechOutputProvider', 'espeak_ng')
            ->set('assistantEspeakNgSpeechUrl', 'http://127.0.0.1:2701/speech')
            ->set('assistantEspeakNgVoice', 'de+f3')
            ->call('saveAssistant')
            ->assertHasNoErrors();

        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');

        $this->assertSame('vosk', $settings['speech_input_provider']);
        $this->assertSame('http://127.0.0.1:2700/transcribe', $settings['vosk_transcription_url']);
        $this->assertSame('espeak_ng', $settings['speech_output_provider']);
        $this->assertSame('http://127.0.0.1:2701/speech', $settings['espeak_ng_speech_url']);
        $this->assertSame('de+f3', $settings['espeak_ng_voice']);
    }

    public function test_server_local_speech_providers_are_saved_when_runtime_is_ready(): void
    {
        $this->mock(LocalAssistantVoiceService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')->atLeast()->once()->andReturn($this->readyLocalVoiceStatus());
        });

        Livewire::test(SettingsPage::class, ['tab' => 'assistant'])
            ->set('assistantSpeechInputProvider', 'whisper_local')
            ->set('assistantSpeechOutputProvider', 'piper_local')
            ->call('saveAssistant')
            ->assertHasNoErrors();

        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');

        $this->assertSame('whisper_local', $settings['speech_input_provider']);
        $this->assertSame('piper_local', $settings['speech_output_provider']);
    }

    public function test_assistant_speech_provider_urls_are_required_for_local_services(): void
    {
        Livewire::test(SettingsPage::class, ['tab' => 'assistant'])
            ->set('assistantSpeechInputProvider', 'vosk')
            ->set('assistantVoskTranscriptionUrl', '')
            ->set('assistantSpeechOutputProvider', 'espeak_ng')
            ->set('assistantEspeakNgSpeechUrl', '')
            ->call('saveAssistant')
            ->assertHasErrors([
                'assistantVoskTranscriptionUrl',
                'assistantEspeakNgSpeechUrl',
            ]);
    }

    public function test_vosk_transcription_endpoint_forwards_audio_and_normalizes_text(): void
    {
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_input_provider' => 'vosk',
            'vosk_transcription_url' => 'http://vosk.test/transcribe',
        ]);

        Http::fake([
            'http://vosk.test/transcribe' => Http::response([
                'result' => ['text' => 'Hallo Workflow'],
            ]),
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('assistant.audio-input.transcribe'), [
                'audio' => UploadedFile::fake()->createWithContent('speech.webm', 'fake-webm-audio'),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('text', 'Hallo Workflow');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://vosk.test/transcribe'
                && str_contains($request->body(), 'name="audio"')
                && str_contains($request->body(), 'name="language"')
                && str_contains($request->body(), 'de-DE');
        });
    }

    public function test_vosk_transcription_endpoint_requires_service_url(): void
    {
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_input_provider' => 'vosk',
            'vosk_transcription_url' => '',
        ]);

        Http::fake();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('assistant.audio-input.transcribe'), [
                'audio' => UploadedFile::fake()->create('speech.webm', 16, 'audio/webm'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Vosk-Spracheingabe ist nicht konfiguriert. Bitte die Vosk Transcription URL in den AI Chatbot-Einstellungen setzen.');

        Http::assertNothingSent();
    }

    public function test_local_whisper_endpoint_transcribes_without_http_provider(): void
    {
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_input_provider' => 'whisper_local',
        ]);

        $this->mock(LocalAssistantVoiceService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('transcribe')
                ->once()
                ->andReturn('Hallo vom lokalen Whisper');
        });
        Http::fake();

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('assistant.audio-input.transcribe'), [
                'audio' => UploadedFile::fake()->createWithContent('speech.webm', 'fake-webm-audio'),
            ]);

        $response
            ->assertOk()
            ->assertHeader('X-AI-Speech-Provider', 'whisper_local')
            ->assertJsonPath('text', 'Hallo vom lokalen Whisper');

        Http::assertNothingSent();
    }

    public function test_espeak_ng_output_provider_streams_service_audio(): void
    {
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_output_provider' => 'espeak_ng',
            'espeak_ng_speech_url' => 'http://espeak.test/speech',
            'espeak_ng_voice' => 'de',
        ]);

        Http::fake([
            'http://espeak.test/speech' => Http::response('RIFFfake-audio', 200, [
                'Content-Type' => 'audio/wav',
            ]),
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('assistant.audio-output.stream'), [
                'text' => 'Audio testen',
                'speed' => 1.2,
            ]);

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/wav');

        $this->assertSame('RIFFfake-audio', $response->streamedContent());

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://espeak.test/speech'
                && $request['text'] === 'Audio testen'
                && $request['voice'] === 'de'
                && $request['speed'] === 1.2
                && $request['format'] === 'wav';
        });
    }

    public function test_local_piper_output_provider_returns_wav_without_http_provider(): void
    {
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_output_provider' => 'piper_local',
        ]);

        $this->mock(LocalAssistantVoiceService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('synthesize')
                ->once()
                ->withArgs(fn (string $text, float $speed, string $connectionId): bool => $text === 'Audio testen'
                    && $speed === 1.1
                    && $connectionId !== '')
                ->andReturn('RIFF'.str_repeat("\0", 128));
        });
        Http::fake();

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('assistant.audio-output.stream'), [
                'text' => 'Audio testen',
                'speed' => 1.1,
            ]);

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertHeader('X-AI-Speech-Provider', 'piper_local');
        $this->assertStringStartsWith('RIFF', $response->getContent());

        Http::assertNothingSent();
    }

    public function test_voice_status_command_activates_ready_local_providers_and_preserves_settings(): void
    {
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'name' => 'Bestehender Copilot',
            'speech_input_provider' => 'browser',
            'speech_output_provider' => 'ai',
        ]);
        config([
            'services.local_assistant_voice.enabled' => true,
            'services.local_assistant_voice.ffmpeg.command' => [PHP_BINARY],
            'services.local_assistant_voice.whisper.command' => [PHP_BINARY],
            'services.local_assistant_voice.whisper.model' => __FILE__,
            'services.local_assistant_voice.piper.command' => [PHP_BINARY],
            'services.local_assistant_voice.piper.model' => __FILE__,
            'services.local_assistant_voice.piper.config' => __FILE__,
        ]);

        $this->artisan('assistant:voice:status', ['--activate' => true])
            ->expectsOutputToContain('Whisper (Spracheingabe) und Piper (Sprachausgabe) wurden aktiviert.')
            ->assertExitCode(0);

        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');

        $this->assertSame('Bestehender Copilot', $settings['name']);
        $this->assertSame('whisper_local', $settings['speech_input_provider']);
        $this->assertSame('piper_local', $settings['speech_output_provider']);
    }

    public function test_voice_status_command_runs_a_piper_to_whisper_smoke_test(): void
    {
        $wave = 'RIFF'.pack('V', 132).'WAVE'.str_repeat("\0", 128);

        $this->mock(LocalAssistantVoiceService::class, function (MockInterface $mock) use ($wave): void {
            $mock->shouldReceive('status')
                ->once()
                ->andReturn($this->readyLocalVoiceStatus());
            $mock->shouldReceive('synthesize')
                ->once()
                ->with(
                    'Hallo. Dies ist ein produktiver Test der lokalen Sprachverarbeitung.',
                    1.0,
                    'assistant-voice-smoke-tts',
                )
                ->andReturn($wave);
            $mock->shouldReceive('transcribe')
                ->once()
                ->withArgs(fn (UploadedFile $audio, string $connectionId): bool => $connectionId === 'assistant-voice-smoke-stt'
                    && $audio->getClientOriginalName() === 'assistant-voice-smoke.wav'
                    && $audio->getSize() === strlen($wave))
                ->andReturn('Hallo, dies ist ein produktiver Test der lokalen Sprachverarbeitung.');
        });

        $this->artisan('assistant:voice:status', ['--smoke' => true])
            ->expectsOutputToContain('Piper/TTS-Smoke: gueltige WAV-Datei')
            ->expectsOutputToContain('Whisper/STT-Smoke: Hallo, dies ist ein produktiver Test')
            ->assertExitCode(0);
    }

    public function test_ai_output_provider_remains_the_default(): void
    {
        config(['services.openrouter.api_key' => '']);

        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'speech_output_provider' => 'ai',
            'espeak_ng_speech_url' => 'http://espeak.test/speech',
        ]);

        Http::fake();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('assistant.audio-output.stream'), [
                'text' => 'Audio testen',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'OpenRouter-Audioausgabe ist nicht vollstaendig konfiguriert. Bitte API-Key, TTS-Modell und Audio-Endpoint pruefen.');

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function readyLocalVoiceStatus(): array
    {
        return [
            'enabled' => true,
            'ready' => true,
            'transcription_ready' => true,
            'synthesis_ready' => true,
            'components' => [
                'ffmpeg' => true,
                'whisper_binary' => true,
                'whisper_model' => true,
                'piper_binary' => true,
                'piper_model' => true,
                'piper_config' => true,
            ],
            'missing' => [],
            'whisper_language' => 'de',
            'piper_mode' => 'cli',
        ];
    }
}
