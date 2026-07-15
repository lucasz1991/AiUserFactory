<?php

namespace Tests\Feature;

use App\Livewire\Admin\Config\SettingsPage;
use App\Models\Setting;
use App\Services\Ai\AiConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class OpenRouterConnectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_save_open_router_persists_models_and_preserves_audio_output_settings(): void
    {
        Setting::setValue('services', 'openrouter', [
            'audio_output_api_url' => 'https://openrouter.ai/api/v1/audio/speech',
            'audio_output_voice' => 'Eve',
            'audio_output_format' => 'wav',
        ]);

        $this->settingsComponent()
            ->set('openRouterApiUrl', 'https://openrouter.ai/api/v1/chat/completions')
            ->set('openRouterApiKey', 'test-key')
            ->set('openRouterTextModel', 'openai/gpt-4o-mini')
            ->set('openRouterDataModel', 'openai/gpt-4o')
            ->set('openRouterImageGenerationModel', 'openai/gpt-image-1')
            ->set('openRouterImageUnderstandingModel', 'openai/gpt-4o')
            ->set('openRouterSpeechToTextModel', 'openai/whisper-1')
            ->set('openRouterTextToSpeechModel', 'x-ai/grok-voice-tts-1.0')
            ->call('saveOpenRouter')
            ->assertHasNoErrors();

        $settings = Setting::getValue('services', 'openrouter');

        $this->assertSame('openai/gpt-4o-mini', $settings['text_model']);
        $this->assertSame('x-ai/grok-voice-tts-1.0', $settings['audio_output_model']);
        $this->assertSame('https://openrouter.ai/api/v1/audio/speech', $settings['audio_output_api_url']);
        $this->assertSame('Eve', $settings['audio_output_voice']);
        $this->assertSame('wav', $settings['audio_output_format']);
    }

    public function test_text_model_test_stores_success_result(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('text')
            ->once()
            ->withArgs(fn (string $prompt, ?string $system, array $options): bool => ($options['model'] ?? null) === 'openai/gpt-4o-mini')
            ->andReturn('Die Testverbindung funktioniert.');
        $this->app->instance(AiConnectionService::class, $ai);

        $this->settingsComponent()
            ->set('openRouterTextModel', 'openai/gpt-4o-mini')
            ->call('testOpenRouterTextModel')
            ->assertSet('openRouterModelTests.text.state', 'success')
            ->assertSet('openRouterModelTests.text.output', 'Die Testverbindung funktioniert.');
    }

    public function test_text_model_test_records_failure_message(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('text')->once()->andThrow(new \RuntimeException('OpenRouter API Fehler: 401'));
        $this->app->instance(AiConnectionService::class, $ai);

        $this->settingsComponent()
            ->set('openRouterTextModel', 'openai/gpt-4o-mini')
            ->call('testOpenRouterTextModel')
            ->assertSet('openRouterModelTests.text.state', 'error')
            ->assertSet('openRouterModelTests.text.output', 'OpenRouter API Fehler: 401');
    }

    public function test_text_model_test_without_model_shows_hint_and_skips_request(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('text');
        $this->app->instance(AiConnectionService::class, $ai);

        $this->settingsComponent()
            ->set('openRouterTextModel', '')
            ->call('testOpenRouterTextModel')
            ->assertSet('openRouterModelTests.text.state', 'error');
    }

    public function test_data_model_test_renders_json_output(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andReturn(['status' => 'ok', 'hinweis' => 'Alles gut.']);
        $this->app->instance(AiConnectionService::class, $ai);

        $component = $this->settingsComponent()
            ->set('openRouterDataModel', 'openai/gpt-4o')
            ->call('testOpenRouterDataModel')
            ->assertSet('openRouterModelTests.data.state', 'success');

        $this->assertStringContainsString('"status": "ok"', $component->get('openRouterModelTests.data.output'));
    }

    public function test_image_generation_test_collects_image_urls(): void
    {
        $response = ['choices' => [['message' => ['images' => [['image_url' => ['url' => 'data:image/png;base64,QUJD']]]]]]];
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('imageGeneration')->once()->andReturn($response);
        $ai->shouldReceive('generatedImageUrls')->once()->with($response)->andReturn(['data:image/png;base64,QUJD']);
        $this->app->instance(AiConnectionService::class, $ai);

        $this->settingsComponent()
            ->set('openRouterImageGenerationModel', 'openai/gpt-image-1')
            ->call('testOpenRouterImageGenerationModel')
            ->assertSet('openRouterModelTests.image_generation.state', 'success')
            ->assertSet('openRouterModelTests.image_generation.images', ['data:image/png;base64,QUJD']);
    }

    public function test_image_understanding_test_sends_uploaded_image_as_data_url(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('imageUnderstanding')
            ->once()
            ->withArgs(fn (string $prompt, string $imageUrl): bool => str_starts_with($imageUrl, 'data:image/'))
            ->andReturn(['choices' => [['message' => ['content' => 'Ein blauer Kreis.']]]]);
        $this->app->instance(AiConnectionService::class, $ai);

        $this->settingsComponent()
            ->set('openRouterImageUnderstandingModel', 'openai/gpt-4o')
            ->set('openRouterVisionTestImage', UploadedFile::fake()->image('test.png', 32, 32))
            ->call('testOpenRouterImageUnderstandingModel')
            ->assertHasNoErrors()
            ->assertSet('openRouterModelTests.image_understanding.state', 'success')
            ->assertSet('openRouterModelTests.image_understanding.output', 'Ein blauer Kreis.');
    }

    public function test_image_understanding_test_requires_an_image(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('imageUnderstanding');
        $this->app->instance(AiConnectionService::class, $ai);

        $this->settingsComponent()
            ->call('testOpenRouterImageUnderstandingModel')
            ->assertHasErrors(['openRouterVisionTestImage']);
    }

    public function test_speech_to_text_test_sends_uploaded_audio_as_data_url(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('speechToText')
            ->once()
            ->withArgs(fn (string $audioUrl): bool => str_starts_with($audioUrl, 'data:audio/mp3;base64,'))
            ->andReturn(['choices' => [['message' => ['content' => 'Hallo Welt.']]]]);
        $this->app->instance(AiConnectionService::class, $ai);

        $this->settingsComponent()
            ->set('openRouterSpeechToTextModel', 'openai/whisper-1')
            ->set('openRouterSpeechTestAudio', UploadedFile::fake()->create('probe.mp3', 64, 'audio/mpeg'))
            ->call('testOpenRouterSpeechToTextModel')
            ->assertHasNoErrors()
            ->assertSet('openRouterModelTests.speech_to_text.state', 'success')
            ->assertSet('openRouterModelTests.speech_to_text.output', 'Hallo Welt.');
    }

    public function test_settings_blade_has_no_audio_output_fields_and_no_tts_test(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/admin/config/settings-page.blade.php'));

        $this->assertStringNotContainsString('openRouterAudioOutputVoice', $blade);
        $this->assertStringNotContainsString('openRouterAudioOutputFormat', $blade);
        $this->assertStringNotContainsString('openRouterAudioOutputApiUrl', $blade);
        $this->assertStringNotContainsString('testOpenRouterTextToSpeechModel', $blade);
        $this->assertStringContainsString('testOpenRouterSpeechToTextModel', $blade);
        $this->assertStringContainsString('testOpenRouterImageUnderstandingModel', $blade);
    }

    protected function settingsComponent()
    {
        return Livewire::test(SettingsPage::class, ['tab' => 'openrouter']);
    }
}
