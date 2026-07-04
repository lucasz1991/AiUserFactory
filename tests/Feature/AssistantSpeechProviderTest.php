<?php

namespace Tests\Feature;

use App\Livewire\Admin\Config\SettingsPage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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
}
