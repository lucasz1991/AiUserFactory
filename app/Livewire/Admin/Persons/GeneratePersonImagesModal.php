<?php

namespace App\Livewire\Admin\Persons;

use App\Jobs\GeneratePersonImages;
use App\Models\File;
use App\Models\Person;
use App\Services\Ai\AiConnectionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class GeneratePersonImagesModal extends Component
{
    public bool $showModal = false;

    public ?int $personId = null;

    public ?Person $person = null;

    public bool $isGeneratingImage = false;

    public int $imageJobPlaceholderCount = 0;

    public string $imageJobStartedAt = '';

    public string $imagePrompt = '';

    public string $imagePromptBrief = '';

    public string $imagePreset = 'profile_portrait';

    public string $imageAspectRatio = '1:1';

    public int $imageCount = 1;

    public bool $setGeneratedImageAsAvatar = true;

    public array $referenceImages = [];

    public array $generatedImages = [];

    #[On('open-person-image-modal')]
    public function open(int $personId): void
    {
        $this->personId = $personId;
        $this->person = Person::query()->findOrFail($personId);
        $this->imagePreset = 'profile_portrait';
        $this->imagePrompt = $this->defaultImagePrompt($this->imagePreset);
        $this->imagePromptBrief = '';
        $this->imageAspectRatio = '1:1';
        $this->imageCount = 1;
        $this->setGeneratedImageAsAvatar = true;
        $this->referenceImages = $this->buildReferenceImagePreview();
        $this->generatedImages = $this->buildGeneratedImagePreview();
        $this->imageJobPlaceholderCount = 0;
        $this->imageJobStartedAt = '';
        $this->isGeneratingImage = false;
        $this->showModal = true;
    }

    public function close(): void
    {
        $this->reset([
            'showModal',
            'personId',
            'person',
            'isGeneratingImage',
            'imageJobPlaceholderCount',
            'imageJobStartedAt',
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
            preview: $this->buildPersonPreview(),
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

        $displayName = $this->person->display_name ?: $this->person->profile_label;

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
        $preview = $this->buildPersonPreview();
        $root = $preview['root'] ?? [];
        $identity = $preview['identity_profile'] ?? [];

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
            'Interessen' => is_array($identity['interests'] ?? null) ? implode(', ', $identity['interests']) : ($identity['interests'] ?? null),
            'Persoenlichkeit' => is_array($identity['personality_traits'] ?? null) ? implode(', ', $identity['personality_traits']) : ($identity['personality_traits'] ?? null),
            'Optische Beschreibung' => $identity['physical_appearance'] ?? null,
            'Bildtyp' => $this->imagePresetOptions()[$preset] ?? 'Profilportrait',
            'Referenzbilder' => $referenceImageCount > 0
                ? $referenceImageCount.' vorhandene Bilddatei(en) sind angehaengt und muessen zur optischen Konsistenz genutzt werden.'
                : 'Keine vorhandenen Bilddateien gefunden.',
            'Format' => $this->imageAspectRatio,
        ], fn (mixed $value): bool => $this->formatContextValue($value) !== '');

        $contextLines = collect($context)
            ->map(fn (mixed $value, string $key): string => '- '.$key.': '.$this->formatContextValue($value))
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

    protected function formatContextValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];

            foreach ($value as $key => $item) {
                $formatted = $this->formatContextValue($item);

                if ($formatted === '') {
                    continue;
                }

                $parts[] = is_string($key) ? $key.': '.$formatted : $formatted;
            }

            return implode(', ', $parts);
        }

        if ($value === null || is_bool($value)) {
            return $value === true ? 'Ja' : '';
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return trim((string) $value);
        }

        return '';
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

    protected function buildPersonPreview(): array
    {
        $identity = is_array($this->person?->identity_profile) ? $this->person->identity_profile : [];
        $bot = is_array($this->person?->bot_profile) ? $this->person->bot_profile : [];

        return [
            'root' => [
                'person_first_name' => $this->person?->person_first_name,
                'person_last_name' => $this->person?->person_last_name,
                'person_alias' => $this->person?->person_alias,
                'person_gender' => $this->person?->person_gender,
                'person_country' => $this->person?->person_country,
                'person_city' => $this->person?->person_city,
            ],
            'identity_profile' => $identity,
            'bot_profile' => $bot,
        ];
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
        $disk = $file->disk ?: 'private';
        $path = (string) $file->path;

        return $this->isSupportedReferenceMime($mime)
            && $path !== ''
            && Storage::disk($disk)->exists($path);
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

    public function render()
    {
        return view('livewire.admin.persons.generate-person-images-modal');
    }
}
