<?php

namespace App\Jobs;

use App\Models\File;
use App\Models\Person;
use App\Services\Ai\AiConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GeneratePersonImages implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $personId,
        public string $prompt,
        public string $preset,
        public string $aspectRatio,
        public int $imageCount = 1,
        public bool $setFirstImageAsAvatar = false,
        public array $preview = [],
        public ?int $userId = null,
    ) {
        $this->imageCount = max(1, min(8, $this->imageCount));
    }

    public function handle(AiConnectionService $ai): void
    {
        $person = Person::query()->find($this->personId);

        if (! $person) {
            return;
        }

        $referenceImageUrls = $this->buildReferenceImageDataUrls($person);
        $storedCount = 0;

        for ($index = 0; $index < $this->imageCount; $index++) {
            $response = $ai->imageGeneration(
                prompt: $this->buildImagePrompt($person, count($referenceImageUrls), $index + 1),
                options: [
                    'reference_images' => $referenceImageUrls,
                    'temperature' => 0.35,
                    'max_completion_tokens' => 1200,
                ],
            );

            $generatedImageUrls = $ai->generatedImageUrls($response);

            if ($generatedImageUrls === []) {
                Log::warning('AI person image job returned no image URLs.', [
                    'person_id' => $person->id,
                    'preset' => $this->preset,
                    'image_index' => $index + 1,
                ]);

                continue;
            }

            $stored = $this->storeGeneratedImages(
                person: $person,
                imageUrls: $generatedImageUrls,
                setAsAvatar: $this->setFirstImageAsAvatar && $index === 0,
                sequenceOffset: $storedCount
            );

            $storedCount += $stored;
        }

        if ($storedCount === 0) {
            throw new \RuntimeException('AI-Bildauftrag abgeschlossen, aber es konnte kein Bild gespeichert werden.');
        }
    }

    protected function buildImagePrompt(Person $person, int $referenceImageCount, int $imageNumber): string
    {
        $root = $this->preview['root'] ?? [];
        $identity = $this->preview['identity_profile'] ?? [];

        $personName = trim(collect([
            $root['person_first_name'] ?? $person->person_first_name,
            $root['person_last_name'] ?? $person->person_last_name,
        ])->filter()->implode(' '));

        $context = array_filter([
            'Name' => $personName,
            'Alias' => $root['person_alias'] ?? $person->person_alias,
            'Geschlecht/Rolle' => $root['person_gender'] ?? $person->person_gender,
            'Land/Stadt' => trim((string) (($root['person_country'] ?? $person->person_country).' '.($root['person_city'] ?? $person->person_city))),
            'Beruf/Taetigkeit' => $identity['occupation'] ?? null,
            'Interessen' => $identity['interests'] ?? null,
            'Persoenlichkeit' => $identity['personality_traits'] ?? null,
            'Optische Beschreibung' => $identity['physical_appearance'] ?? null,
            'Bildtyp' => $this->imagePresetOptions()[$this->preset] ?? 'Profilportrait',
            'Bildnummer' => $imageNumber.' von '.$this->imageCount,
            'Referenzbilder' => $referenceImageCount > 0
                ? $referenceImageCount.' vorhandene Bilddatei(en) sind angehaengt und muessen zur optischen Konsistenz genutzt werden.'
                : 'Keine vorhandenen Bilddateien gefunden.',
            'Format' => $this->aspectRatio,
        ], fn (mixed $value): bool => $this->formatContextValue($value) !== '');

        $contextLines = collect($context)
            ->map(fn (mixed $value, string $key): string => '- '.$key.': '.$this->formatContextValue($value))
            ->implode(PHP_EOL);

        $presetRules = match ($this->preset) {
            'hobby_lifestyle' => 'Szene: Hobby, Freizeit, Sport, Reisen, Musik, Kunst, Kochen oder eine andere zur Persona passende Aktivitaet. Das Bild soll natuerlich und nicht gestellt wirken.',
            'work_context' => 'Szene: glaubwuerdige Arbeitssituation, Arbeitsplatz, Kundentermin, Studio, Werkstatt, Buero oder unterwegs im Beruf. Kleidung und Umgebung passen zur Taetigkeit.',
            'creative_character' => 'Szene: auffaellige Location, besonderes Outfit oder markante Lichtstimmung. Kreativ, aber weiterhin realistisch und wiedererkennbar.',
            default => 'Szene: klares Profilportrait mit Gesicht im Fokus. Dieses Bild ist als Profilbild geeignet.',
        };

        return trim($this->prompt).PHP_EOL.PHP_EOL.
            'Person-Kontext:'.PHP_EOL.$contextLines.PHP_EOL.PHP_EOL.
            'Bildtyp-Regel: '.$presetRules.PHP_EOL.
            'Regeln: Erzeuge genau ein Bild der beschriebenen Person. Bestehende Referenzbilder haben Vorrang vor allgemeinen Textannahmen. Erhalte die Identitaet und optischen Merkmale aus den Referenzbildern. Bei mehreren Bildern variiere Szene, Pose, Licht oder Ausschnitt leicht, ohne die Person zu veraendern. Keine Datei- oder Login-Daten, keine Bildpfade, keine Textelemente im Bild.';
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

    protected function buildReferenceImageDataUrls(Person $person): array
    {
        $maxBytes = 10 * 1024 * 1024;

        return $this->referenceImageFiles($person)
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

                return 'data:'.$mime.';base64,'.base64_encode(Storage::disk($disk)->get($path));
            })
            ->filter()
            ->values()
            ->toArray();
    }

    protected function referenceImageFiles(Person $person): Collection
    {
        $person->loadMissing('filePool');

        $files = collect($person->files()
            ->where('mime_type', 'like', 'image/%')
            ->latest('id')
            ->get());

        if ($person->filePool) {
            $files = $files->merge($person->filePool->files()
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

    protected function storeGeneratedImages(Person $person, array $imageUrls, bool $setAsAvatar, int $sequenceOffset): int
    {
        $filePool = $person->filePool()->firstOrCreate([
            'title' => 'Standard Ordner',
            'type' => class_basename(Person::class),
            'description' => '',
        ]);

        $storedCount = 0;
        $fileType = match ($this->preset) {
            'hobby_lifestyle' => 'ai-hobby-image',
            'work_context' => 'ai-work-image',
            'creative_character' => 'ai-creative-image',
            default => 'ai-profile-portrait',
        };
        $namePrefix = match ($this->preset) {
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

            $path = 'uploads/ai-generated-images/'.$person->id.'/'.Str::uuid().'.'.$decoded['extension'];
            Storage::disk('private')->put($path, $decoded['contents']);

            $file = $filePool->files()->create([
                'user_id' => $this->userId,
                'name' => $namePrefix.' '.now()->format('Y-m-d H-i-s').' '.($sequenceOffset + $index + 1),
                'path' => $path,
                'disk' => 'private',
                'mime_type' => $decoded['mime'],
                'type' => $fileType,
                'size' => strlen($decoded['contents']),
            ]);

            if ($setAsAvatar && $this->preset === 'profile_portrait' && $storedCount === 0) {
                $this->setFileAsPersonAvatar($person, $file);
            }

            $storedCount++;
        }

        return $storedCount;
    }

    protected function setFileAsPersonAvatar(Person $person, File $sourceFile): void
    {
        $person->loadMissing('filePool');

        $person->files()
            ->where('type', 'avatar')
            ->get()
            ->each
            ->delete();

        $avatarFile = $person->files()->create([
            'filepool_id' => $person->filePool?->id,
            'user_id' => $this->userId ?: $sourceFile->user_id,
            'name' => $sourceFile->name ?: 'Profilbild',
            'path' => $sourceFile->path,
            'disk' => $sourceFile->disk ?: 'private',
            'mime_type' => $sourceFile->mime_type,
            'type' => 'avatar',
            'size' => $sourceFile->size,
        ]);

        $person->forceFill([
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

    protected function imagePresetOptions(): array
    {
        return [
            'profile_portrait' => 'Profilportrait',
            'hobby_lifestyle' => 'Hobby / Lifestyle',
            'work_context' => 'Arbeit / Business',
            'creative_character' => 'Ausgefallen',
        ];
    }
}
