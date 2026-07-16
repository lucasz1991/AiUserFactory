<?php

namespace App\Livewire\Admin\Config;

use App\Models\Setting;
use App\Services\Ai\AiConnectionService;
use App\Services\Ai\LocalAssistantVoiceService;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class SettingsPage extends Component
{
    use WithFileUploads;

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

    /** @var array<string, array{state:string, output:string, images:array, duration_ms:int}> */
    public array $openRouterModelTests = [];

    public $openRouterVisionTestImage = null;

    public $openRouterSpeechTestAudio = null;

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

    public array $assistantLocalVoiceStatus = [];

    public string $assistantVisionFallbackModels = '';

    public int $assistantCopilotMaxMinutes = 90;

    public int $assistantCopilotMaxRepairIterations = 15;

    public int $assistantCopilotMaxProbeActions = 60;

    public int $assistantCopilotMaxSameStateRepeats = 2;

    public float $assistantCopilotMaxCostUsd = 0.0;

    public bool $assistantCopilotAutoExecute = true;

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

            'openRouterTimeout' => ['required', 'integer', 'min:5', 'max:600'],
            'openRouterTemperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'openRouterMaxCompletionTokens' => ['required', 'integer', 'min:1', 'max:200000'],
            'openRouterStreamEnabled' => ['boolean'],
        ]);

        $existing = Setting::getValue('services', 'openrouter');
        $existing = is_array($existing) ? $existing : [];

        // Audioausgabe-Detailwerte (audio_output_api_url/voice/format) werden hier
        // bewusst nicht mehr editiert oder ueberschrieben: Sie gehoeren zur
        // Assistant-Sprachverarbeitung (AssistantAudioOutputStreamController) und
        // bleiben ueber den Merge mit dem Bestand erhalten.
        Setting::setValue('services', 'openrouter', [
            ...$existing,
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
            'audio_output_model' => trim($validated['openRouterTextToSpeechModel']),

            'timeout' => (int) $validated['openRouterTimeout'],
            'temperature' => (float) $validated['openRouterTemperature'],
            'max_completion_tokens' => (int) $validated['openRouterMaxCompletionTokens'],
            'stream_enabled' => (bool) $validated['openRouterStreamEnabled'],
        ]);

        session()->flash('success', 'OpenRouter-Einstellungen wurden gespeichert.');
        $this->dispatch('showAlert', 'OpenRouter gespeichert.', 'success');
    }

    public function testOpenRouterTextModel(): void
    {
        $this->runOpenRouterModelTest('text', $this->openRouterTextModel, function (AiConnectionService $ai, string $model): array {
            $output = $ai->text(
                'Antworte mit genau einem kurzen deutschen Satz, dass die Testverbindung funktioniert.',
                null,
                ['model' => $model, '_timeout' => 60],
            );

            if (trim($output) === '') {
                throw new \RuntimeException('Das Modell hat eine leere Antwort geliefert.');
            }

            return ['output' => trim($output)];
        });
    }

    public function testOpenRouterDataModel(): void
    {
        $this->runOpenRouterModelTest('data', $this->openRouterDataModel, function (AiConnectionService $ai, string $model): array {
            $decoded = $ai->json(
                'Gib ein JSON-Objekt mit den Schluesseln "status" (Wert "ok") und "hinweis" (ein kurzer deutscher Satz) zurueck.',
                null,
                ['model' => $model, '_timeout' => 60],
            );

            return ['output' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: ''];
        });
    }

    public function testOpenRouterImageGenerationModel(): void
    {
        $this->runOpenRouterModelTest('image_generation', $this->openRouterImageGenerationModel, function (AiConnectionService $ai, string $model): array {
            $response = $ai->imageGeneration(
                'Erzeuge ein einfaches Testbild: ein blauer Kreis auf weissem Hintergrund.',
                ['model' => $model, '_timeout' => 180],
            );
            $images = $ai->generatedImageUrls($response);

            if ($images === []) {
                throw new \RuntimeException('Das Modell hat kein Bild zurueckgegeben.');
            }

            return ['output' => 'Bild wurde erzeugt.', 'images' => array_slice($images, 0, 2)];
        });
    }

    public function testOpenRouterImageUnderstandingModel(): void
    {
        $validated = $this->validate([
            'openRouterVisionTestImage' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);
        $image = $validated['openRouterVisionTestImage'];
        $mime = $image->getMimeType() ?: 'image/png';
        $dataUrl = 'data:'.$mime.';base64,'.base64_encode((string) $image->get());

        $this->runOpenRouterModelTest('image_understanding', $this->openRouterImageUnderstandingModel, function (AiConnectionService $ai, string $model) use ($dataUrl): array {
            $response = $ai->imageUnderstanding(
                'Beschreibe kurz auf Deutsch, was auf dem Bild zu sehen ist.',
                $dataUrl,
                ['model' => $model, '_timeout' => 120],
            );
            $output = trim((string) data_get($response, 'choices.0.message.content', ''));

            if ($output === '') {
                throw new \RuntimeException('Das Modell hat keine Bildbeschreibung geliefert.');
            }

            return ['output' => $output];
        });
    }

    public function testOpenRouterSpeechToTextModel(): void
    {
        $validated = $this->validate([
            'openRouterSpeechTestAudio' => ['required', 'file', 'mimes:mp3,wav,m4a,ogg,webm,mpga', 'max:15360'],
        ]);
        $audio = $validated['openRouterSpeechTestAudio'];
        $format = strtolower((string) $audio->getClientOriginalExtension()) ?: 'mp3';
        $dataUrl = 'data:audio/'.$format.';base64,'.base64_encode((string) $audio->get());

        $this->runOpenRouterModelTest('speech_to_text', $this->openRouterSpeechToTextModel, function (AiConnectionService $ai, string $model) use ($dataUrl): array {
            $response = $ai->speechToText($dataUrl, ['model' => $model, '_timeout' => 120]);
            $output = trim((string) data_get($response, 'choices.0.message.content', ''));

            if ($output === '') {
                throw new \RuntimeException('Das Modell hat kein Transkript geliefert.');
            }

            return ['output' => $output];
        });
    }

    protected function runOpenRouterModelTest(string $profile, string $model, callable $runner): void
    {
        $model = trim($model);

        if ($model === '') {
            $this->openRouterModelTests[$profile] = [
                'state' => 'error',
                'output' => 'Bitte zuerst ein Modell eintragen.',
                'images' => [],
                'duration_ms' => 0,
            ];

            return;
        }

        $startedAt = microtime(true);

        try {
            $result = $runner(app(AiConnectionService::class), $model);
            $this->openRouterModelTests[$profile] = [
                'state' => 'success',
                'output' => (string) ($result['output'] ?? ''),
                'images' => is_array($result['images'] ?? null) ? $result['images'] : [],
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (Throwable $exception) {
            $this->openRouterModelTests[$profile] = [
                'state' => 'error',
                'output' => $exception->getMessage(),
                'images' => [],
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }
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
            'assistantSpeechInputProvider' => ['required', 'string', 'in:browser,whisper_local,vosk'],
            'assistantSpeechOutputProvider' => ['required', 'string', 'in:piper_local,ai,espeak_ng'],
            'assistantVoskTranscriptionUrl' => ['nullable', 'required_if:assistantSpeechInputProvider,vosk', 'url', 'max:2048'],
            'assistantEspeakNgSpeechUrl' => ['nullable', 'required_if:assistantSpeechOutputProvider,espeak_ng', 'url', 'max:2048'],
            'assistantEspeakNgVoice' => ['nullable', 'string', 'max:80'],
            'assistantVisionFallbackModels' => ['nullable', 'string', 'max:4000'],
            'assistantCopilotMaxMinutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'assistantCopilotMaxRepairIterations' => ['required', 'integer', 'min:1', 'max:100'],
            'assistantCopilotMaxProbeActions' => ['required', 'integer', 'min:1', 'max:500'],
            'assistantCopilotMaxSameStateRepeats' => ['required', 'integer', 'min:1', 'max:10'],
            'assistantCopilotMaxCostUsd' => ['required', 'numeric', 'min:0', 'max:10000'],
            'assistantCopilotAutoExecute' => ['boolean'],
        ]);

        $this->refreshAssistantLocalVoiceStatus();
        $localVoiceInvalid = false;

        if ($validated['assistantSpeechInputProvider'] === 'whisper_local'
            && ! ($this->assistantLocalVoiceStatus['transcription_ready'] ?? false)) {
            $localVoiceInvalid = true;
            $this->addError(
                'assistantSpeechInputProvider',
                'Whisper kann erst aktiviert werden, wenn ffmpeg, Whisper CLI und das Modell serverlokal bereit sind.',
            );
        }

        if ($validated['assistantSpeechOutputProvider'] === 'piper_local'
            && ! ($this->assistantLocalVoiceStatus['synthesis_ready'] ?? false)) {
            $localVoiceInvalid = true;
            $this->addError(
                'assistantSpeechOutputProvider',
                'Piper kann erst aktiviert werden, wenn Piper CLI, Modell und Konfiguration serverlokal bereit sind.',
            );
        }

        if ($localVoiceInvalid) {
            return;
        }

        $visionFallbackModels = collect(preg_split('/[\r\n,]+/', (string) ($validated['assistantVisionFallbackModels'] ?? '')) ?: [])
            ->map(fn (string $model): string => trim($model))
            ->filter()
            ->unique()
            ->values()
            ->all();

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
            'vision_fallback_models' => $visionFallbackModels,
            'optimization_defaults' => [
                'max_minutes' => (int) $validated['assistantCopilotMaxMinutes'],
                'max_repair_iterations' => (int) $validated['assistantCopilotMaxRepairIterations'],
                'max_probe_actions' => (int) $validated['assistantCopilotMaxProbeActions'],
                'max_same_state_repeats' => (int) $validated['assistantCopilotMaxSameStateRepeats'],
                'max_cost_usd' => round((float) $validated['assistantCopilotMaxCostUsd'], 4),
                'auto_execute_workflow_actions' => (bool) $validated['assistantCopilotAutoExecute'],
            ],
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

    public function refreshAssistantLocalVoiceStatus(): void
    {
        $this->assistantLocalVoiceStatus = app(LocalAssistantVoiceService::class)->status();
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
        $this->refreshAssistantLocalVoiceStatus();
        $visionFallbackModels = is_array($settings['vision_fallback_models'] ?? null)
            ? $settings['vision_fallback_models']
            : preg_split('/[\r\n,]+/', (string) ($settings['vision_fallback_models'] ?? ''));
        $this->assistantVisionFallbackModels = collect($visionFallbackModels ?: [])
            ->map(fn (mixed $model): string => trim((string) $model))
            ->filter()
            ->unique()
            ->implode("\n");
        $optimizationDefaults = is_array($settings['optimization_defaults'] ?? null)
            ? $settings['optimization_defaults']
            : [];
        $this->assistantCopilotMaxMinutes = max(5, min(1440, (int) ($optimizationDefaults['max_minutes'] ?? 90)));
        $this->assistantCopilotMaxRepairIterations = max(1, min(100, (int) ($optimizationDefaults['max_repair_iterations'] ?? 15)));
        $this->assistantCopilotMaxProbeActions = max(1, min(500, (int) ($optimizationDefaults['max_probe_actions'] ?? 60)));
        $this->assistantCopilotMaxSameStateRepeats = max(1, min(10, (int) ($optimizationDefaults['max_same_state_repeats'] ?? 2)));
        $this->assistantCopilotMaxCostUsd = max(0, min(10000, (float) ($optimizationDefaults['max_cost_usd'] ?? 0)));
        $this->assistantCopilotAutoExecute = filter_var(
            $optimizationDefaults['auto_execute_workflow_actions'] ?? true,
            FILTER_VALIDATE_BOOL,
        );
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

        return in_array($provider, ['browser', 'whisper_local', 'vosk'], true) ? $provider : 'browser';
    }

    protected function normalizeAssistantSpeechOutputProvider(mixed $provider): string
    {
        $provider = trim((string) $provider);

        return in_array($provider, ['piper_local', 'ai', 'espeak_ng'], true) ? $provider : 'ai';
    }
}
