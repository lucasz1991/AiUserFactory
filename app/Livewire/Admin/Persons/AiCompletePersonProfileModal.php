<?php

namespace App\Livewire\Admin\Persons;

use App\Jobs\GeneratePersonImages;
use App\Models\File;
use App\Models\Person;
use App\Services\Ai\AiConnectionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class AiCompletePersonProfileModal extends Component
{
    public bool $showModal = false;

    public bool $showImageModal = false;

    public ?int $personId = null;

    public ?Person $person = null;

    public bool $isGenerating = false;

    public bool $isGeneratingImage = false;

    public int $imageJobPlaceholderCount = 0;

    public string $imageJobStartedAt = '';

    public array $preview = [];

    public string $profilePrompt = '';

    public string $imagePrompt = '';

    public string $imagePromptBrief = '';

    public string $imagePreset = 'profile_portrait';

    public string $imageAspectRatio = '1:1';

    public int $imageCount = 1;

    public bool $setGeneratedImageAsAvatar = true;

    public array $referenceImages = [];

    public array $generatedImages = [];

    public array $allowedRootFields = [
        'person_first_name',
        'person_last_name',
        'person_alias',
        'person_date_of_birth',
        'person_gender',
        'person_email',
        'person_phone',
        'person_timezone',
        'person_address_line1',
        'person_address_line2',
        'person_postal_code',
        'person_city',
        'person_state',
        'person_country',
        'person_notes',
    ];

    public array $allowedIdentityFields = [
        'nationality',
        'occupation',
        'relationship_status',
        'physical_appearance',
        'languages',
        'interests',
        'personality_traits',
        'values',
        'daily_routine',
        'background_story',
    ];

    public array $allowedBotFields = [
        'communication_style',
        'writing_style',
        'behavior_guidelines',
    ];

    #[On('open-ai-complete-person-profile')]
    public function open(int $personId): void
    {
        $this->personId = $personId;
        $this->person = Person::query()->findOrFail($personId);
        $this->preview = $this->buildEditablePreview();
        $this->profilePrompt = '';
        $this->imagePreset = 'profile_portrait';
        $this->imagePrompt = $this->defaultImagePrompt($this->imagePreset);
        $this->imagePromptBrief = '';
        $this->imageAspectRatio = '1:1';
        $this->imageCount = 1;
        $this->setGeneratedImageAsAvatar = true;
        $this->referenceImages = $this->buildReferenceImagePreview();
        $this->generatedImages = [];
        $this->imageJobPlaceholderCount = 0;
        $this->imageJobStartedAt = '';
        $this->showModal = true;
    }

    public function close(): void
    {
        $this->reset([
            'showModal',
            'showImageModal',
            'personId',
            'person',
            'isGenerating',
            'isGeneratingImage',
            'imageJobPlaceholderCount',
            'imageJobStartedAt',
            'preview',
            'profilePrompt',
            'imagePrompt',
            'imagePromptBrief',
            'imagePreset',
            'imageAspectRatio',
            'imageCount',
            'setGeneratedImageAsAvatar',
            'referenceImages',
            'generatedImages',
        ]);
    }

    public function openImageModal(): void
    {
        if (! $this->person) {
            return;
        }

        $this->referenceImages = $this->buildReferenceImagePreview();
        $this->generatedImages = $this->buildGeneratedImagePreview();
        $this->imagePreset = $this->imagePreset ?: 'profile_portrait';
        $this->imagePrompt = $this->imagePrompt ?: $this->defaultImagePrompt($this->imagePreset);
        $this->imagePromptBrief = '';
        $this->imageAspectRatio = $this->imageAspectRatio ?: '1:1';
        $this->imageCount = max(1, min(8, (int) $this->imageCount));
        $this->setGeneratedImageAsAvatar = $this->imagePreset === 'profile_portrait';
        $this->showImageModal = true;
    }

    public function closeImageModal(): void
    {
        $this->showImageModal = false;
    }

    public function generate(AiConnectionService $ai): void
    {
        if (! $this->person) {
            return;
        }

        $validated = $this->validate([
            'profilePrompt' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->isGenerating = true;

        try {
            $result = $ai->json(
                prompt: json_encode($this->buildAiContext($validated['profilePrompt'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                system: $this->systemPrompt(),
                options: [
                    'temperature' => 0.7,
                    'max_completion_tokens' => 3000,
                ]
            );

            $this->preview = $this->sanitizeAiResult($result);

            session()->flash('success', 'AI-Vorschlag wurde erstellt. Bitte pruefen und speichern.');
        } catch (Throwable $exception) {
            Log::error('AI person completion failed', [
                'person_id' => $this->personId,
                'message' => $exception->getMessage(),
            ]);

            session()->flash('error', $exception->getMessage());
        } finally {
            $this->isGenerating = false;
        }
    }

    public function generateImage(): void
    {
        if (! $this->person) {
            return;
        }

        $validated = $this->validate([
            'imagePrompt' => ['required', 'string', 'max:5000'],
            'imagePreset' => ['required', 'string', 'in:profile_portrait,hobby_lifestyle,work_context,creative_character'],
            'imageAspectRatio' => ['required', 'string', 'in:1:1,2:3,3:2,3:4,4:3,4:5,5:4,9:16,16:9,21:9'],
            'imageCount' => ['required', 'integer', 'min:1', 'max:8'],
            'setGeneratedImageAsAvatar' => ['boolean'],
        ]);

        GeneratePersonImages::dispatch(
            personId: (int) $this->person->id,
            prompt: $validated['imagePrompt'],
            preset: $validated['imagePreset'],
            aspectRatio: $validated['imageAspectRatio'],
            imageCount: (int) $validated['imageCount'],
            setFirstImageAsAvatar: (bool) $validated['setGeneratedImageAsAvatar'],
            preview: $this->preview,
            userId: auth()->id(),
        );

        $this->isGeneratingImage = true;
        $this->imageJobPlaceholderCount = (int) $validated['imageCount'];
        $this->imageJobStartedAt = now()->toIso8601String();
        $this->generatedImages = [];

        session()->flash('success', 'Bildauftrag wurde gestartet. Die Bilder werden im Hintergrund erzeugt und automatisch im FilePool gespeichert.');
    }

    public function refreshImageStatus(): void
    {
        if (! $this->person) {
            return;
        }

        $this->referenceImages = $this->buildReferenceImagePreview();
        $this->generatedImages = $this->buildGeneratedImagePreview(
            $this->imageJobStartedAt !== '' ? Carbon::parse($this->imageJobStartedAt) : null
        );

        if ($this->isGeneratingImage && count($this->generatedImages) >= $this->imageJobPlaceholderCount) {
            $this->isGeneratingImage = false;
            $this->imageJobPlaceholderCount = 0;
            $this->imageJobStartedAt = '';
            $this->dispatch('refreshPersonDetail');
        }
    }

    public function improveImagePrompt(AiConnectionService $ai): void
    {
        if (! $this->person) {
            return;
        }

        $validated = $this->validate([
            'imagePromptBrief' => ['nullable', 'string', 'max:2500'],
            'imagePrompt' => ['nullable', 'string', 'max:5000'],
            'imagePreset' => ['required', 'string', 'in:profile_portrait,hobby_lifestyle,work_context,creative_character'],
            'imageAspectRatio' => ['required', 'string', 'in:1:1,2:3,3:2,3:4,4:3,4:5,5:4,9:16,16:9,21:9'],
        ]);

        $brief = trim($validated['imagePromptBrief'] ?: $validated['imagePrompt'] ?: '');

        if ($brief === '') {
            $this->addError('imagePromptBrief', 'Bitte beschreibe kurz, wie das Bild werden soll.');

            return;
        }

        try {
            $prompt = $ai->text(
                prompt: json_encode([
                    'user_image_description' => $brief,
                    'image_type' => $this->imagePresetOptions()[$validated['imagePreset']] ?? 'Profilportrait',
                    'aspect_ratio' => $validated['imageAspectRatio'],
                    'person_context' => $this->buildImagePrompt('', $validated['imagePreset'], count($this->referenceImages)),
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                system: $this->imagePromptSystemPrompt(),
                options: [
                    'temperature' => 0.45,
                    'max_completion_tokens' => 900,
                ]
            );

            $this->imagePrompt = trim($prompt) ?: $this->imagePrompt;

            session()->flash('success', 'Bildprompt wurde mit AI vorbereitet.');
        } catch (Throwable $exception) {
            Log::error('AI image prompt generation failed', [
                'person_id' => $this->personId,
                'message' => $exception->getMessage(),
            ]);

            session()->flash('error', $exception->getMessage());
        }
    }

    public function applyImagePreset(string $preset): void
    {
        if (! array_key_exists($preset, $this->imagePresetOptions())) {
            return;
        }

        $this->imagePreset = $preset;
        $this->imagePrompt = $this->defaultImagePrompt($preset);
        $this->imagePromptBrief = '';
        $this->imageAspectRatio = $preset === 'profile_portrait' ? '1:1' : '4:5';
        $this->setGeneratedImageAsAvatar = $preset === 'profile_portrait';
    }

    public function save(): void
    {
        if (! $this->person) {
            return;
        }

        $validated = $this->validate([
            'preview.root.person_first_name' => ['nullable', 'string', 'max:120'],
            'preview.root.person_last_name' => ['nullable', 'string', 'max:120'],
            'preview.root.person_alias' => ['nullable', 'string', 'max:120'],
            'preview.root.person_date_of_birth' => ['nullable', 'date', 'before:today'],
            'preview.root.person_gender' => ['nullable', 'string', 'max:60'],
            'preview.root.person_email' => ['nullable', 'email', 'max:255'],
            'preview.root.person_phone' => ['nullable', 'string', 'max:80'],
            'preview.root.person_timezone' => ['nullable', 'string', 'max:80'],
            'preview.root.person_address_line1' => ['nullable', 'string', 'max:255'],
            'preview.root.person_address_line2' => ['nullable', 'string', 'max:255'],
            'preview.root.person_postal_code' => ['nullable', 'string', 'max:40'],
            'preview.root.person_city' => ['nullable', 'string', 'max:120'],
            'preview.root.person_state' => ['nullable', 'string', 'max:120'],
            'preview.root.person_country' => ['nullable', 'string', 'max:120'],
            'preview.root.person_notes' => ['nullable', 'string', 'max:12000'],

            'preview.identity_profile.nationality' => ['nullable', 'string', 'max:120'],
            'preview.identity_profile.occupation' => ['nullable', 'string', 'max:255'],
            'preview.identity_profile.relationship_status' => ['nullable', 'string', 'max:120'],
            'preview.identity_profile.physical_appearance' => ['nullable', 'string', 'max:6000'],
            'preview.identity_profile.languages' => ['nullable', 'string', 'max:2000'],
            'preview.identity_profile.interests' => ['nullable', 'string', 'max:4000'],
            'preview.identity_profile.personality_traits' => ['nullable', 'string', 'max:4000'],
            'preview.identity_profile.values' => ['nullable', 'string', 'max:4000'],
            'preview.identity_profile.daily_routine' => ['nullable', 'string', 'max:8000'],
            'preview.identity_profile.background_story' => ['nullable', 'string', 'max:20000'],

            'preview.bot_profile.communication_style' => ['nullable', 'string', 'max:4000'],
            'preview.bot_profile.writing_style' => ['nullable', 'string', 'max:4000'],
            'preview.bot_profile.behavior_guidelines' => ['nullable', 'string', 'max:12000'],
        ]);

        $root = Arr::only($validated['preview']['root'] ?? [], $this->allowedRootFields);
        $root['person_date_of_birth'] = $this->nullableString($root['person_date_of_birth'] ?? null);

        $identityProfile = is_array($this->person->identity_profile)
            ? $this->person->identity_profile
            : [];

        $botProfile = is_array($this->person->bot_profile)
            ? $this->person->bot_profile
            : [];

        foreach ($this->allowedIdentityFields as $field) {
            $value = $validated['preview']['identity_profile'][$field] ?? null;

            $identityProfile[$field] = in_array($field, [
                'languages',
                'interests',
                'personality_traits',
                'values',
            ], true)
                ? $this->splitValues((string) $value)
                : $this->nullableString($value);
        }

        foreach ($this->allowedBotFields as $field) {
            $botProfile[$field] = $this->nullableString(
                $validated['preview']['bot_profile'][$field] ?? null
            );
        }

        $this->person->forceFill([
            ...$root,
            'identity_profile' => $identityProfile,
            'bot_profile' => $botProfile,
        ])->save();

        $this->dispatch('refreshPersonDetail');

        session()->flash('success', 'Personendaten wurden mit AI-Daten komplettiert.');

        $this->close();
    }

    protected function buildEditablePreview(): array
    {
        $identityProfile = is_array($this->person->identity_profile)
            ? $this->person->identity_profile
            : [];

        $botProfile = is_array($this->person->bot_profile)
            ? $this->person->bot_profile
            : [];

        return [
            'root' => collect($this->allowedRootFields)
                ->mapWithKeys(fn (string $field) => [
                    $field => $field === 'person_date_of_birth'
                        ? ($this->person->person_date_of_birth?->format('Y-m-d') ?? '')
                        : (string) ($this->person->{$field} ?? ''),
                ])
                ->toArray(),

            'identity_profile' => [
                'nationality' => (string) ($identityProfile['nationality'] ?? ''),
                'occupation' => (string) ($identityProfile['occupation'] ?? ''),
                'relationship_status' => (string) ($identityProfile['relationship_status'] ?? ''),
                'physical_appearance' => (string) ($identityProfile['physical_appearance'] ?? ''),
                'languages' => implode(PHP_EOL, $this->normalizeList($identityProfile['languages'] ?? [])),
                'interests' => implode(PHP_EOL, $this->normalizeList($identityProfile['interests'] ?? [])),
                'personality_traits' => implode(PHP_EOL, $this->normalizeList($identityProfile['personality_traits'] ?? [])),
                'values' => implode(PHP_EOL, $this->normalizeList($identityProfile['values'] ?? [])),
                'daily_routine' => (string) ($identityProfile['daily_routine'] ?? ''),
                'background_story' => (string) ($identityProfile['background_story'] ?? ''),
            ],

            'bot_profile' => [
                'communication_style' => (string) ($botProfile['communication_style'] ?? ''),
                'writing_style' => (string) ($botProfile['writing_style'] ?? ''),
                'behavior_guidelines' => (string) ($botProfile['behavior_guidelines'] ?? ''),
            ],
        ];
    }

    protected function buildAiContext(string $profilePrompt = ''): array
    {
        $dateOfBirth = (string) data_get($this->preview, 'root.person_date_of_birth', '');
        $age = $dateOfBirth !== ''
            ? $this->ageLabelFromDate($dateOfBirth)
            : '';

        return [
            'task' => 'Komplettiere und verbessere editierbare Textfelder einer fiktiven Persona realistisch und konsistent.',
            'user_prompt' => trim($profilePrompt),
            'current_age_from_birthdate' => $age,
            'existing_person_data' => $this->preview ?: $this->buildEditablePreview(),
            'allowed_fields_only' => [
                'root' => $this->allowedRootFields,
                'identity_profile' => $this->allowedIdentityFields,
                'bot_profile' => $this->allowedBotFields,
            ],
            'strict_exclusions' => [
                'instagram',
                'instagram_username',
                'instagram_password',
                'instagram_profile_url',
                'social_accounts',
                'login_username',
                'password',
                'cookies',
                'session',
                'browser_profile_path',
                'cookie_file_path',
                'avatar_path',
                'profile_image',
                'files',
                'images',
                'uploads',
            ],
        ];
    }

    public function imagePresetOptions(): array
    {
        return [
            'profile_portrait' => 'Profilportrait',
            'hobby_lifestyle' => 'Hobby / Lifestyle',
            'work_context' => 'Arbeit / Business',
            'creative_character' => 'Ausgefallen',
        ];
    }

    protected function defaultImagePrompt(string $preset = 'profile_portrait'): string
    {
        if (! $this->person) {
            return '';
        }

        $displayName = trim(collect([
            $this->person->person_first_name,
            $this->person->person_last_name,
        ])->filter()->implode(' '));

        $displayName = $displayName !== ''
            ? $displayName
            : ($this->person->person_alias ?: $this->person->profile_label);

        return match ($preset) {
            'hobby_lifestyle' => trim(sprintf(
                'Erstelle ein realistisches Lifestyle-Bild von %s bei einem passenden Hobby oder einer Freizeitaktivitaet. Waehle die Szene aus Interessen, Hintergrund und Persoenlichkeit. Das Gesicht und die optischen Merkmale muessen zu den Referenzbildern passen. Keine Schrift, keine Logos, kein Wasserzeichen.',
                $displayName
            )),
            'work_context' => trim(sprintf(
                'Erstelle ein realistisches Arbeits- oder Business-Bild von %s in einem glaubwuerdigen beruflichen Umfeld. Nutze Beruf, Stadt, Stil und Hintergrund der Person. Gesicht, Frisur und Statur muessen zu den Referenzbildern passen. Keine Schrift, keine Logos, kein Wasserzeichen.',
                $displayName
            )),
            'creative_character' => trim(sprintf(
                'Erstelle ein ausgefallenes, aber realistisches Charakterbild von %s mit besonderer Stimmung, Kleidung oder Location. Es darf kreativ wirken, muss aber die Person anhand der Referenzbilder klar wiedererkennbar halten. Keine Schrift, keine Logos, kein Wasserzeichen.',
                $displayName
            )),
            default => trim(sprintf(
                'Erstelle ein realistisches Profilportrait von %s mit klarem Gesicht, natuerlichem Licht und neutralem Hintergrund. Nutze alle vorhandenen Referenzbilder, um Aussehen, Statur, Gesichtszuege, Frisur und markante Merkmale konsistent beizubehalten. Keine Schrift, keine Logos, kein Wasserzeichen.',
                $displayName
            )),
        };
    }

    protected function buildImagePrompt(string $userPrompt, string $preset, int $referenceImageCount): string
    {
        $root = $this->preview['root'] ?? [];
        $identity = $this->preview['identity_profile'] ?? [];

        $personName = trim(collect([
            $root['person_first_name'] ?? '',
            $root['person_last_name'] ?? '',
        ])->filter()->implode(' '));

        $context = array_filter([
            'Name' => $personName,
            'Alias' => $root['person_alias'] ?? null,
            'Geschlecht/Rolle' => $root['person_gender'] ?? null,
            'Land/Stadt' => trim((string) (($root['person_country'] ?? '').' '.($root['person_city'] ?? ''))),
            'Beruf/Taetigkeit' => $identity['occupation'] ?? null,
            'Interessen' => $identity['interests'] ?? null,
            'Persoenlichkeit' => $identity['personality_traits'] ?? null,
            'Optische Beschreibung' => $identity['physical_appearance'] ?? null,
            'Bildtyp' => $this->imagePresetOptions()[$preset] ?? 'Profilportrait',
            'Referenzbilder' => $referenceImageCount > 0
                ? $referenceImageCount.' vorhandene Bilddatei(en) sind angehaengt und muessen zur optischen Konsistenz genutzt werden.'
                : 'Keine vorhandenen Bilddateien gefunden.',
            'Format' => $this->imageAspectRatio,
        ], static fn (mixed $value): bool => trim((string) $value) !== '');

        $contextLines = collect($context)
            ->map(fn (mixed $value, string $key): string => '- '.$key.': '.trim((string) $value))
            ->implode(PHP_EOL);

        $presetRules = match ($preset) {
            'hobby_lifestyle' => 'Szene: Hobby, Freizeit, Sport, Reisen, Musik, Kunst, Kochen oder eine andere zur Persona passende Aktivitaet. Das Bild soll natuerlich und nicht gestellt wirken.',
            'work_context' => 'Szene: glaubwuerdige Arbeitssituation, Arbeitsplatz, Kundentermin, Studio, Werkstatt, Buero oder unterwegs im Beruf. Kleidung und Umgebung passen zur Taetigkeit.',
            'creative_character' => 'Szene: auffaellige Location, besonderes Outfit oder markante Lichtstimmung. Kreativ, aber weiterhin realistisch und wiedererkennbar.',
            default => 'Szene: klares Profilportrait mit Gesicht im Fokus. Dieses Bild ist als Profilbild geeignet.',
        };

        return trim($userPrompt).PHP_EOL.PHP_EOL.
            'Person-Kontext:'.PHP_EOL.$contextLines.PHP_EOL.PHP_EOL.
            'Bildtyp-Regel: '.$presetRules.PHP_EOL.
            'Regeln: Erzeuge nur ein Bild der beschriebenen Person. Bestehende Referenzbilder haben Vorrang vor allgemeinen Textannahmen. Erhalte die Identitaet und optischen Merkmale aus den Referenzbildern. Keine Datei- oder Login-Daten, keine Bildpfade, keine Textelemente im Bild.';
    }

    protected function imagePromptSystemPrompt(): string
    {
        return <<<'PROMPT'
Du bist ein Bildprompt-Designer fuer realistische fiktive Persona-Bilder.

Erstelle aus der kurzen Nutzerbeschreibung einen praezisen deutschen Bildprompt.
Der Prompt soll:
- die gewuenschte Szene, Stimmung, Kleidung, Umgebung und Kameraperspektive konkretisieren,
- die vorhandene Persona und optische Beschreibung respektieren,
- vorhandene Referenzbilder als wichtigste Quelle fuer Gesicht und Aussehen behandeln,
- keine echten Personen, Marken, Logos, Wasserzeichen, Schrift im Bild, Datei- oder Login-Daten verlangen.

Antworte nur mit dem fertigen Prompttext, ohne Markdown und ohne Erklaerung.
PROMPT;
    }

    protected function buildReferenceImagePreview(): array
    {
        return $this->referenceImageFiles()
            ->map(fn (File $file): array => [
                'id' => $file->id,
                'name' => $file->name_with_extension,
                'type' => $file->type,
                'url' => $file->getEphemeralPublicUrl(15),
            ])
            ->values()
            ->toArray();
    }

    protected function buildGeneratedImagePreview(?Carbon $since = null): array
    {
        return $this->generatedImageFiles($since)
            ->map(fn (File $file): array => [
                'id' => $file->id,
                'name' => $file->name_with_extension,
                'type' => $file->type,
                'url' => $file->getEphemeralPublicUrl(15),
            ])
            ->values()
            ->toArray();
    }

    protected function buildReferenceImageDataUrls(): array
    {
        $maxBytes = 10 * 1024 * 1024;

        return $this->referenceImageFiles()
            ->map(function (File $file) use ($maxBytes): ?string {
                $disk = $file->disk ?: 'private';
                $path = (string) $file->path;

                if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                    return null;
                }

                $size = (int) ($file->size ?: Storage::disk($disk)->size($path));

                if ($size <= 0 || $size > $maxBytes) {
                    return null;
                }

                $mime = strtolower((string) ($file->mime_type ?: Storage::disk($disk)->mimeType($path)));

                if (! $this->isSupportedReferenceMime($mime)) {
                    return null;
                }

                $contents = Storage::disk($disk)->get($path);

                return 'data:'.$mime.';base64,'.base64_encode($contents);
            })
            ->filter()
            ->values()
            ->toArray();
    }

    protected function referenceImageFiles(): Collection
    {
        if (! $this->person) {
            return collect();
        }

        $this->person->loadMissing('filePool');

        $files = collect($this->person->files()
            ->where('mime_type', 'like', 'image/%')
            ->latest('id')
            ->get());

        if ($this->person->filePool) {
            $files = $files->merge($this->person->filePool->files()
                ->where('mime_type', 'like', 'image/%')
                ->latest('id')
                ->get());
        }

        return $files
            ->filter(fn (File $file): bool => $this->isUsableReferenceImage($file))
            ->unique(fn (File $file): string => $this->fileReferenceKey($file))
            ->sortByDesc(fn (File $file): int => $this->referencePriority($file))
            ->take(4)
            ->values();
    }

    protected function generatedImageFiles(?Carbon $since = null): Collection
    {
        if (! $this->person) {
            return collect();
        }

        $this->person->loadMissing('filePool');

        $types = ['ai-profile-portrait', 'ai-hobby-image', 'ai-work-image', 'ai-creative-image'];
        $files = collect($this->person->files()
            ->where('mime_type', 'like', 'image/%')
            ->whereIn('type', $types)
            ->latest('id')
            ->get());

        if ($this->person->filePool) {
            $files = $files->merge($this->person->filePool->files()
                ->where('mime_type', 'like', 'image/%')
                ->whereIn('type', $types)
                ->latest('id')
                ->get());
        }

        $files = $files->filter(fn (File $file): bool => $this->isUsableReferenceImage($file));

        if ($since) {
            $files = $files->filter(fn (File $file): bool => $file->created_at && $file->created_at->greaterThanOrEqualTo($since));
        }

        return $files
            ->unique(fn (File $file): string => $this->fileReferenceKey($file))
            ->sortByDesc(fn (File $file): int => $file->created_at?->timestamp ?? 0)
            ->take(8)
            ->values();
    }

    protected function isUsableReferenceImage(File $file): bool
    {
        if ($file->isExpired()) {
            return false;
        }

        $mime = strtolower((string) $file->mime_type);

        if (! $this->isSupportedReferenceMime($mime)) {
            return false;
        }

        $disk = $file->disk ?: 'private';
        $path = (string) $file->path;

        return $path !== '' && Storage::disk($disk)->exists($path);
    }

    protected function isSupportedReferenceMime(string $mime): bool
    {
        return in_array(strtolower($mime), [
            'image/png',
            'image/jpeg',
            'image/jpg',
            'image/webp',
            'image/gif',
        ], true);
    }

    protected function referencePriority(File $file): int
    {
        $timestamp = $file->created_at?->timestamp ?? 0;

        return ($file->type === 'avatar' ? 10_000_000_000 : 0) + $timestamp;
    }

    protected function fileReferenceKey(File $file): string
    {
        return ($file->disk ?: 'private').':'.trim((string) $file->path);
    }

    protected function storeGeneratedImages(array $imageUrls, string $preset, bool $setAsAvatar): array
    {
        if (! $this->person) {
            return [];
        }

        $filePool = $this->person->filePool()->firstOrCreate([
            'title' => 'Standard Ordner',
            'type' => class_basename(Person::class),
            'description' => '',
        ]);

        $storedImages = [];
        $fileType = match ($preset) {
            'hobby_lifestyle' => 'ai-hobby-image',
            'work_context' => 'ai-work-image',
            'creative_character' => 'ai-creative-image',
            default => 'ai-profile-portrait',
        };
        $namePrefix = match ($preset) {
            'hobby_lifestyle' => 'AI Hobby Bild',
            'work_context' => 'AI Arbeitsbild',
            'creative_character' => 'AI Charakterbild',
            default => 'AI Profilportrait',
        };

        foreach ($imageUrls as $index => $imageUrl) {
            $decoded = $this->decodeImageDataUrl((string) $imageUrl);

            if (! $decoded) {
                continue;
            }

            $path = 'uploads/ai-generated-images/'.$this->person->id.'/'.Str::uuid().'.'.$decoded['extension'];
            Storage::disk('private')->put($path, $decoded['contents']);

            $file = $filePool->files()->create([
                'user_id' => auth()->id(),
                'name' => $namePrefix.' '.now()->format('Y-m-d H-i-s').($index > 0 ? ' '.($index + 1) : ''),
                'path' => $path,
                'disk' => 'private',
                'mime_type' => $decoded['mime'],
                'type' => $fileType,
                'size' => strlen($decoded['contents']),
            ]);

            if ($setAsAvatar && $preset === 'profile_portrait' && $index === 0) {
                $this->setFileAsPersonAvatar($file);
            }

            $storedImages[] = [
                'id' => $file->id,
                'name' => $file->name_with_extension,
                'url' => $file->getEphemeralPublicUrl(15),
            ];
        }

        if ($storedImages === []) {
            throw new \RuntimeException('Das generierte Bild konnte nicht gespeichert werden.');
        }

        return $storedImages;
    }

    protected function setFileAsPersonAvatar(File $sourceFile): void
    {
        $this->person->loadMissing('filePool');

        $this->person->files()
            ->where('type', 'avatar')
            ->get()
            ->each
            ->delete();

        $avatarFile = $this->person->files()->create([
            'filepool_id' => $this->person->filePool?->id,
            'user_id' => auth()->id() ?: $sourceFile->user_id,
            'name' => $sourceFile->name ?: 'Profilbild',
            'path' => $sourceFile->path,
            'disk' => $sourceFile->disk ?: 'private',
            'mime_type' => $sourceFile->mime_type,
            'type' => 'avatar',
            'size' => $sourceFile->size,
        ]);

        $this->person->forceFill([
            'avatar_path' => $avatarFile->path,
        ])->save();
    }

    protected function decodeImageDataUrl(string $dataUrl): ?array
    {
        if (! preg_match('/^data:(image\/(?:png|jpe?g|webp|gif));base64,(.+)$/i', trim($dataUrl), $matches)) {
            return null;
        }

        $mime = strtolower($matches[1]) === 'image/jpg' ? 'image/jpeg' : strtolower($matches[1]);
        $contents = base64_decode(str_replace(' ', '+', $matches[2]), true);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return [
            'mime' => $mime,
            'extension' => match ($mime) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'png',
            },
            'contents' => $contents,
        ];
    }

    protected function systemPrompt(): string
    {
        return <<<PROMPT
Du bist ein Datenassistent fuer eine interne Persona-Verwaltung.

Telefonnummern und Adressen muessen offensichtlich realistisch sein.

Du darfst ausschliesslich diese JSON-Struktur zurueckgeben:

{
  "root": {
    "person_first_name": "",
    "person_last_name": "",
    "person_alias": "",
    "person_date_of_birth": "",
    "person_gender": "",
    "person_email": "",
    "person_phone": "",
    "person_timezone": "",
    "person_address_line1": "",
    "person_address_line2": "",
    "person_postal_code": "",
    "person_city": "",
    "person_state": "",
    "person_country": "",
    "person_notes": ""
  },
  "identity_profile": {
    "nationality": "",
    "occupation": "",
    "relationship_status": "",
    "physical_appearance": "",
    "languages": "",
    "interests": "",
    "personality_traits": "",
    "values": "",
    "daily_routine": "",
    "background_story": ""
  },
  "bot_profile": {
    "communication_style": "",
    "writing_style": "",
    "behavior_guidelines": ""
  }
}

Regeln:
- Antworte nur als valides JSON.
- Keine Markdown-Codebloecke.
- Keine Bilder, Dateien, Uploads oder Pfade.
- Keine Instagram-Daten veraendern, erfinden oder ergaenzen.
- Keine Login-, Cookie-, Session- oder Scraper-Daten.
- Bestehende Werte respektieren, ausser der Nutzerprompt verlangt klar eine Anpassung.
- Leere Textfelder sinnvoll ergaenzen.
- Wenn der Nutzer im Prompt Alter oder Altersbereich vorgibt, gib person_date_of_birth als plausibles Datum im Format YYYY-MM-DD zurueck. Erfinde kein exaktes Geburtsdatum, wenn vorhandene Daten oder Nutzerprompt dagegen sprechen.
- Die optische Beschreibung beschreibt nur sichtbare Merkmale der Person in neutraler Sprache.
- Listenfelder als Zeilenliste ausgeben.
PROMPT;
    }

    public function previewAgeLabel(): string
    {
        return $this->ageLabelFromDate((string) data_get($this->preview, 'root.person_date_of_birth', ''));
    }

    protected function ageLabelFromDate(string $date): string
    {
        $date = trim($date);

        if ($date === '') {
            return '';
        }

        try {
            return Carbon::parse($date)->age.' Jahre';
        } catch (Throwable) {
            return '';
        }
    }

    protected function sanitizeAiResult(array $result): array
    {
        return [
            'root' => collect($this->allowedRootFields)
                ->mapWithKeys(fn (string $field) => [
                    $field => $this->sanitizeText(
                        data_get($result, "root.{$field}", data_get($this->preview, "root.{$field}", ''))
                    ),
                ])
                ->toArray(),

            'identity_profile' => collect($this->allowedIdentityFields)
                ->mapWithKeys(fn (string $field) => [
                    $field => $this->sanitizeText(
                        data_get($result, "identity_profile.{$field}", data_get($this->preview, "identity_profile.{$field}", ''))
                    ),
                ])
                ->toArray(),

            'bot_profile' => collect($this->allowedBotFields)
                ->mapWithKeys(fn (string $field) => [
                    $field => $this->sanitizeText(
                        data_get($result, "bot_profile.{$field}", data_get($this->preview, "bot_profile.{$field}", ''))
                    ),
                ])
                ->toArray(),
        ];
    }

    protected function sanitizeText(mixed $value): string
    {
        if (is_array($value)) {
            return implode(PHP_EOL, $this->normalizeList($value));
        }

        return trim((string) $value);
    }

    protected function splitValues(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            preg_split('/[\r\n,;]+/', $value) ?: []
        )));
    }

    protected function normalizeList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        )));
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function render()
    {
        return view('livewire.admin.persons.ai-complete-person-profile-modal');
    }
}
