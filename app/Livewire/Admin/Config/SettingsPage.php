<?php

namespace App\Livewire\Admin\Config;

use App\Models\Setting;
use Livewire\Component;

class SettingsPage extends Component
{
    public string $activeTab = 'scraper-transfer';

    public string $baseApiUrl = '';
    public string $apiPassword = '';

    public string $openRouterApiUrl = '';
    public string $openRouterApiKey = '';
    public string $openRouterRefererUrl = '';
    public string $openRouterModelTitle = '';

    public string $openRouterTextModel = '';
    public string $openRouterDataModel = '';
    public string $openRouterImageGenerationModel = '';
    public string $openRouterImageUnderstandingModel = '';
    public string $openRouterSpeechToTextModel = '';
    public string $openRouterTextToSpeechModel = '';
    public string $openRouterAudioOutputApiUrl = '';
    public string $openRouterAudioOutputVoice = 'Eve';
    public string $openRouterAudioOutputFormat = 'mp3';

    public int $openRouterTimeout = 120;
    public float $openRouterTemperature = 0.4;
    public int $openRouterMaxCompletionTokens = 1500;

    public bool $openRouterStreamEnabled = true;

    public bool $assistantEnabled = true;
    public string $assistantName = 'Workflow Copilot';
    public string $assistantInstructions = '';
    public int $assistantMaxToolRounds = 5;
    public bool $assistantAutoReadDefault = false;
    public float $assistantSpeechRate = 1.0;
    public string $assistantSpeechInputProvider = 'browser';
    public string $assistantSpeechOutputProvider = 'ai';
    public string $assistantVoskTranscriptionUrl = '';
    public string $assistantEspeakNgSpeechUrl = '';
    public string $assistantEspeakNgVoice = 'de';

    // ClientController settings tab
    public string $ccServerDomain = '';
    public string $ccFallbackServerDomain = '';
    public bool $ccRequireSignedJobs = true;
    public bool $ccAllowServerRebind = true;
    public int $ccHeartbeatIntervalSeconds = 30;
    public int $ccJobTimeoutSeconds = 180;
    public string $ccBootstrapApiKey = 'followflow-default-node-key-change-me';

    public function mount(string $tab = 'scraper-transfer'): void
    {
        $this->activeTab = $this->normalizeTab($tab);

        $this->loadScraperSettings();
        $this->loadOpenRouterSettings();
        $this->loadAssistantSettings();
        $this->loadClientControllerSettings();
    }

    public function switchTab(string $tab): void
    {
        $this->redirectRoute('admin.settings', ['tab' => $this->normalizeTab($tab)], navigate: true);
    }

    public function saveScraperTransfer(): void
    {
        $validated = $this->validate([
            'baseApiUrl' => ['required', 'url', 'max:2048'],
            'apiPassword' => ['required', 'string', 'max:512'],
        ]);

        $existing = Setting::getValue('services', 'webaidetective_base');
        $existing = is_array($existing) ? $existing : [];

        Setting::setValue('services', 'webaidetective_base', [
            ...$existing,
            'scraper_profile_sync_url' => trim($validated['baseApiUrl']),
            'scraper_profile_sync_password' => trim($validated['apiPassword']),
        ]);

        session()->flash('success', 'Einstellungen fuer Scraper Transfer wurden gespeichert.');
        $this->dispatch('showAlert', 'Scraper-Transfer gespeichert.', 'success');
    }

    public function saveOpenRouter(): void
    {
        $validated = $this->validate([
            'openRouterApiUrl' => ['required', 'url', 'max:2048'],
            'openRouterApiKey' => ['required', 'string', 'max:512'],
            'openRouterRefererUrl' => ['nullable', 'url', 'max:2048'],
            'openRouterModelTitle' => ['nullable', 'string', 'max:255'],

            'openRouterTextModel' => ['required', 'string', 'max:255'],
            'openRouterDataModel' => ['required', 'string', 'max:255'],
            'openRouterImageGenerationModel' => ['required', 'string', 'max:255'],
            'openRouterImageUnderstandingModel' => ['required', 'string', 'max:255'],
            'openRouterSpeechToTextModel' => ['required', 'string', 'max:255'],
            'openRouterTextToSpeechModel' => ['required', 'string', 'max:255'],
            'openRouterAudioOutputApiUrl' => ['nullable', 'url', 'max:2048'],
            'openRouterAudioOutputVoice' => ['nullable', 'string', 'max:80'],
            'openRouterAudioOutputFormat' => ['required', 'string', 'in:mp3,wav,opus,pcm'],

            'openRouterTimeout' => ['required', 'integer', 'min:5', 'max:600'],
            'openRouterTemperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'openRouterMaxCompletionTokens' => ['required', 'integer', 'min:1', 'max:200000'],
            'openRouterStreamEnabled' => ['boolean'],
        ]);

        Setting::setValue('services', 'openrouter', [
            'api_url' => trim($validated['openRouterApiUrl']),
            'api_key' => trim($validated['openRouterApiKey']),
            'referer_url' => trim((string) ($validated['openRouterRefererUrl'] ?? '')),
            'model_title' => trim((string) ($validated['openRouterModelTitle'] ?? '')),

            'text_model' => trim($validated['openRouterTextModel']),
            'data_model' => trim($validated['openRouterDataModel']),
            'image_generation_model' => trim($validated['openRouterImageGenerationModel']),
            'image_understanding_model' => trim($validated['openRouterImageUnderstandingModel']),
            'speech_to_text_model' => trim($validated['openRouterSpeechToTextModel']),
            'text_to_speech_model' => trim($validated['openRouterTextToSpeechModel']),
            'audio_output_api_url' => trim((string) ($validated['openRouterAudioOutputApiUrl'] ?? '')),
            'audio_output_model' => trim($validated['openRouterTextToSpeechModel']),
            'audio_output_voice' => trim((string) ($validated['openRouterAudioOutputVoice'] ?? config('services.openrouter.audio_output_voice', 'Eve'))) ?: config('services.openrouter.audio_output_voice', 'Eve'),
            'audio_output_format' => trim($validated['openRouterAudioOutputFormat']),

            'timeout' => (int) $validated['openRouterTimeout'],
            'temperature' => (float) $validated['openRouterTemperature'],
            'max_completion_tokens' => (int) $validated['openRouterMaxCompletionTokens'],
            'stream_enabled' => (bool) $validated['openRouterStreamEnabled'],
        ]);

        session()->flash('success', 'OpenRouter-Einstellungen wurden gespeichert.');
        $this->dispatch('showAlert', 'OpenRouter gespeichert.', 'success');
    }

    public function saveAssistant(): void
    {
        $validated = $this->validate([
            'assistantEnabled' => ['boolean'],
            'assistantName' => ['required', 'string', 'max:80'],
            'assistantInstructions' => ['nullable', 'string', 'max:8000'],
            'assistantMaxToolRounds' => ['required', 'integer', 'min:1', 'max:8'],
            'assistantAutoReadDefault' => ['boolean'],
            'assistantSpeechRate' => ['required', 'numeric', 'min:0.5', 'max:2'],
            'assistantSpeechInputProvider' => ['required', 'string', 'in:browser,vosk'],
            'assistantSpeechOutputProvider' => ['required', 'string', 'in:ai,espeak_ng'],
            'assistantVoskTranscriptionUrl' => ['nullable', 'required_if:assistantSpeechInputProvider,vosk', 'url', 'max:2048'],
            'assistantEspeakNgSpeechUrl' => ['nullable', 'required_if:assistantSpeechOutputProvider,espeak_ng', 'url', 'max:2048'],
            'assistantEspeakNgVoice' => ['nullable', 'string', 'max:80'],
        ]);

        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'enabled' => (bool) $validated['assistantEnabled'],
            'name' => trim($validated['assistantName']),
            'instructions' => trim((string) ($validated['assistantInstructions'] ?? '')),
            'max_tool_rounds' => (int) $validated['assistantMaxToolRounds'],
            'auto_read_default' => (bool) $validated['assistantAutoReadDefault'],
            'speech_rate' => (float) $validated['assistantSpeechRate'],
            'speech_input_provider' => trim($validated['assistantSpeechInputProvider']),
            'speech_output_provider' => trim($validated['assistantSpeechOutputProvider']),
            'vosk_transcription_url' => trim((string) ($validated['assistantVoskTranscriptionUrl'] ?? '')),
            'espeak_ng_speech_url' => trim((string) ($validated['assistantEspeakNgSpeechUrl'] ?? '')),
            'espeak_ng_voice' => trim((string) ($validated['assistantEspeakNgVoice'] ?? '')) ?: 'de',
        ]);

        session()->flash('success', 'AI Chatbot-Einstellungen wurden gespeichert.');
        $this->dispatch('showAlert', 'AI Chatbot gespeichert.', 'success');
    }

    public function saveClientControllerSettings(): void
    {
        $validated = $this->validate([
            'ccServerDomain' => ['required', 'url', 'max:2048'],
            'ccFallbackServerDomain' => ['nullable', 'url', 'max:2048'],
            'ccRequireSignedJobs' => ['boolean'],
            'ccAllowServerRebind' => ['boolean'],
            'ccHeartbeatIntervalSeconds' => ['required', 'integer', 'min:5', 'max:3600'],
            'ccJobTimeoutSeconds' => ['required', 'integer', 'min:5', 'max:86400'],
            'ccBootstrapApiKey' => ['required', 'string', 'min:16', 'max:255'],
        ]);

        Setting::setValue('client_controller', 'server', [
            'server_domain' => trim($validated['ccServerDomain']),
            'fallback_server_domain' => trim((string) ($validated['ccFallbackServerDomain'] ?? '')),
            'require_signed_jobs' => (bool) $validated['ccRequireSignedJobs'],
            'allow_server_rebind' => (bool) $validated['ccAllowServerRebind'],
            'default_heartbeat_interval_seconds' => (int) $validated['ccHeartbeatIntervalSeconds'],
            'default_job_timeout_seconds' => (int) $validated['ccJobTimeoutSeconds'],
        ]);

        Setting::setValue('client_controller', 'security', [
            'bootstrap_api_key' => trim($validated['ccBootstrapApiKey']),
        ]);

        session()->flash('success', 'ClientController-Einstellungen wurden gespeichert.');
        $this->dispatch('showAlert', 'ClientController Einstellungen gespeichert.', 'success');
    }

    public function render()
    {
        return view('livewire.admin.config.settings-page')->layout('layouts.master');
    }

    protected function loadScraperSettings(): void
    {
        $settings = Setting::getValue('services', 'webaidetective_base');
        $settings = is_array($settings) ? $settings : [];

        $this->baseApiUrl = trim((string) ($settings['scraper_profile_sync_url'] ?? config('services.webaidetective_base.scraper_profile_sync_url')));
        $this->apiPassword = trim((string) ($settings['scraper_profile_sync_password'] ?? $settings['scraper_profile_sync_token'] ?? config('services.webaidetective_base.scraper_profile_sync_password')));
    }

    protected function loadOpenRouterSettings(): void
    {
        $settings = Setting::getValue('services', 'openrouter');
        $settings = is_array($settings) ? $settings : [];

        $this->openRouterApiUrl = trim((string) (
            $settings['api_url']
            ?? $settings['base_url']
            ?? config('services.openrouter.api_url', 'https://openrouter.ai/api/v1/chat/completions')
        ));

        $this->openRouterApiKey = trim((string) ($settings['api_key'] ?? config('services.openrouter.api_key')));
        $this->openRouterRefererUrl = trim((string) ($settings['referer_url'] ?? $settings['site_url'] ?? config('services.openrouter.referer_url', config('app.url'))));
        $this->openRouterModelTitle = trim((string) ($settings['model_title'] ?? $settings['app_name'] ?? config('services.openrouter.model_title', config('app.name'))));

        $this->openRouterTextModel = trim((string) ($settings['text_model'] ?? config('services.openrouter.text_model')));
        $this->openRouterDataModel = trim((string) ($settings['data_model'] ?? $settings['analysis_model'] ?? config('services.openrouter.data_model')));
        $this->openRouterImageGenerationModel = trim((string) ($settings['image_generation_model'] ?? $settings['image_model'] ?? config('services.openrouter.image_generation_model')));
        $this->openRouterImageUnderstandingModel = trim((string) ($settings['image_understanding_model'] ?? $settings['vision_model'] ?? config('services.openrouter.image_understanding_model')));
        $this->openRouterSpeechToTextModel = trim((string) ($settings['speech_to_text_model'] ?? config('services.openrouter.speech_to_text_model')));
        $this->openRouterTextToSpeechModel = trim((string) ($settings['text_to_speech_model'] ?? config('services.openrouter.text_to_speech_model')));
        if (in_array(strtolower($this->openRouterTextToSpeechModel), ['openai/tts-1', 'openai/tts-1-hd'], true)) {
            $this->openRouterTextToSpeechModel = (string) config('services.openrouter.text_to_speech_model', 'x-ai/grok-voice-tts-1.0');
        }
        $this->openRouterAudioOutputApiUrl = trim((string) ($settings['audio_output_api_url'] ?? config('services.openrouter.audio_output_api_url', 'https://openrouter.ai/api/v1/audio/speech')));
        $this->openRouterAudioOutputVoice = trim((string) ($settings['audio_output_voice'] ?? config('services.openrouter.audio_output_voice', 'Eve'))) ?: config('services.openrouter.audio_output_voice', 'Eve');
        if (str_starts_with(strtolower($this->openRouterTextToSpeechModel), 'x-ai/') && in_array(strtolower($this->openRouterAudioOutputVoice), ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer', 'kore'], true)) {
            $this->openRouterAudioOutputVoice = (string) config('services.openrouter.audio_output_voice', 'Eve');
        }
        $this->openRouterAudioOutputFormat = trim((string) ($settings['audio_output_format'] ?? config('services.openrouter.audio_output_format', 'mp3'))) ?: 'mp3';

        $this->openRouterTimeout = (int) ($settings['timeout'] ?? config('services.openrouter.timeout', 120));
        $this->openRouterTemperature = (float) ($settings['temperature'] ?? config('services.openrouter.temperature', 0.4));
        $this->openRouterMaxCompletionTokens = (int) ($settings['max_completion_tokens'] ?? config('services.openrouter.max_completion_tokens', 1500));
        $this->openRouterStreamEnabled = (bool) ($settings['stream_enabled'] ?? config('services.openrouter.stream_enabled', true));
    }

    protected function loadAssistantSettings(): void
    {
        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');
        $settings = is_array($settings) ? $settings : [];

        $this->assistantEnabled = (bool) ($settings['enabled'] ?? true);
        $this->assistantName = trim((string) ($settings['name'] ?? 'Workflow Copilot')) ?: 'Workflow Copilot';
        $this->assistantInstructions = trim((string) ($settings['instructions'] ?? ''));
        $this->assistantMaxToolRounds = max(1, min(8, (int) ($settings['max_tool_rounds'] ?? 5)));
        $this->assistantAutoReadDefault = (bool) ($settings['auto_read_default'] ?? false);
        $this->assistantSpeechRate = max(0.5, min(2.0, (float) ($settings['speech_rate'] ?? 1.0)));
        $this->assistantSpeechInputProvider = $this->normalizeAssistantSpeechInputProvider($settings['speech_input_provider'] ?? 'browser');
        $this->assistantSpeechOutputProvider = $this->normalizeAssistantSpeechOutputProvider($settings['speech_output_provider'] ?? 'ai');
        $this->assistantVoskTranscriptionUrl = trim((string) ($settings['vosk_transcription_url'] ?? ''));
        $this->assistantEspeakNgSpeechUrl = trim((string) ($settings['espeak_ng_speech_url'] ?? ''));
        $this->assistantEspeakNgVoice = trim((string) ($settings['espeak_ng_voice'] ?? 'de')) ?: 'de';
    }

    protected function loadClientControllerSettings(): void
    {
        $server = Setting::getValue('client_controller', 'server');
        $server = is_array($server) ? $server : [];

        $security = Setting::getValue('client_controller', 'security');
        $security = is_array($security) ? $security : [];

        $this->ccServerDomain = trim((string) ($server['server_domain'] ?? config('app.url')));
        $this->ccFallbackServerDomain = trim((string) ($server['fallback_server_domain'] ?? ''));
        $this->ccRequireSignedJobs = (bool) ($server['require_signed_jobs'] ?? true);
        $this->ccAllowServerRebind = (bool) ($server['allow_server_rebind'] ?? true);
        $this->ccHeartbeatIntervalSeconds = (int) ($server['default_heartbeat_interval_seconds'] ?? 30);
        $this->ccJobTimeoutSeconds = (int) ($server['default_job_timeout_seconds'] ?? 180);
        $this->ccBootstrapApiKey = trim((string) ($security['bootstrap_api_key'] ?? 'followflow-default-node-key-change-me'));
    }

    protected function normalizeTab(string $tab): string
    {
        return in_array($tab, ['scraper-transfer', 'openrouter', 'assistant', 'client-controller', 'activity-planning', 'processes', 'mail-registration'], true)
            ? $tab
            : 'scraper-transfer';
    }

    protected function normalizeAssistantSpeechInputProvider(mixed $provider): string
    {
        $provider = trim((string) $provider);

        return in_array($provider, ['browser', 'vosk'], true) ? $provider : 'browser';
    }

    protected function normalizeAssistantSpeechOutputProvider(mixed $provider): string
    {
        $provider = trim((string) $provider);

        return in_array($provider, ['ai', 'espeak_ng'], true) ? $provider : 'ai';
    }
}
