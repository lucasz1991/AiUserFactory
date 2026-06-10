<?php

namespace App\Livewire\Admin\Config;

use App\Models\File;
use App\Models\Person;
use App\Services\Simulation\PersonaActivityPlanner;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\WithFileUploads;

class PersonDetail extends PersonList
{
    use WithFileUploads;

    public string $profileId = '';

    public array $profileDetail = [];

    public ?Person $personRecord = null;

    public ?File $avatarFile = null;

    public string $avatarUrl = '';

    public array $imageFiles = [];

    public mixed $avatarUpload = null;

    public string $aiNationality = '';

    public string $aiOccupation = '';

    public string $aiRelationshipStatus = '';

    public string $aiLanguages = '';

    public string $aiInterests = '';

    public string $aiPersonalityTraits = '';

    public string $aiValues = '';

    public string $aiCommunicationStyle = '';

    public string $aiWritingStyle = '';

    public string $aiDailyRoutine = '';

    public string $aiBackgroundStory = '';

    public string $aiBehaviorGuidelines = '';

    public int $activitySimulationDays = 7;

    public string $activitySimulationIntensity = 'balanced';

    public string $activitySimulationSeed = '';

    public array $activitySimulation = [];

    public function mount(?string $profileId = null): void
    {
        parent::mount();

        $this->profileId = $profileId !== null && trim($profileId) !== ''
            ? trim($profileId)
            : $this->activeProfileId;

        $this->activeProfileId = $this->profileId;
        $this->refreshProfileDetail();
    }

    public function render()
    {
        return view('livewire.admin.config.person-detail')->layout('layouts.master');
    }

    public function openEditProfile(): void
    {
        $this->editProfile($this->profileId);
    }

    public function openRuntimeSettingsModal(): void
    {
        $this->activeProfileId = $this->profileId;

        parent::openRuntimeSettingsModal();
    }

    public function saveProfile(): void
    {
        parent::saveProfile();

        $this->refreshProfileDetail();
    }

    public function saveRuntimeSettings(): void
    {
        $this->activeProfileId = $this->profileId;

        parent::saveRuntimeSettings();
        $this->refreshProfileDetail();
    }

    public function buildInstagramSession(): void
    {
        $this->activeProfileId = $this->profileId;

        parent::buildInstagramSession();
        $this->refreshProfileDetail();
    }

    #[On('refreshPersonDetail')]
    public function refreshFromChildComponent(): void
    {
        $this->refreshProfileDetail();
    }

    public function saveAiProfile(): void
    {
        if (! $this->personRecord) {
            return;
        }

        $validated = $this->validate([
            'aiNationality' => ['nullable', 'string', 'max:120'],
            'aiOccupation' => ['nullable', 'string', 'max:255'],
            'aiRelationshipStatus' => ['nullable', 'string', 'max:120'],
            'aiLanguages' => ['nullable', 'string', 'max:2000'],
            'aiInterests' => ['nullable', 'string', 'max:4000'],
            'aiPersonalityTraits' => ['nullable', 'string', 'max:4000'],
            'aiValues' => ['nullable', 'string', 'max:4000'],
            'aiCommunicationStyle' => ['nullable', 'string', 'max:4000'],
            'aiWritingStyle' => ['nullable', 'string', 'max:4000'],
            'aiDailyRoutine' => ['nullable', 'string', 'max:8000'],
            'aiBackgroundStory' => ['nullable', 'string', 'max:20000'],
            'aiBehaviorGuidelines' => ['nullable', 'string', 'max:12000'],
        ]);

        $identityProfile = is_array($this->personRecord->identity_profile) ? $this->personRecord->identity_profile : [];
        $botProfile = is_array($this->personRecord->bot_profile) ? $this->personRecord->bot_profile : [];

        $identityProfile['nationality'] = $this->nullableString($validated['aiNationality'] ?? null);
        $identityProfile['occupation'] = $this->nullableString($validated['aiOccupation'] ?? null);
        $identityProfile['relationship_status'] = $this->nullableString($validated['aiRelationshipStatus'] ?? null);
        $identityProfile['languages'] = $this->splitMultilineValues($validated['aiLanguages'] ?? '');
        $identityProfile['interests'] = $this->splitMultilineValues($validated['aiInterests'] ?? '');
        $identityProfile['personality_traits'] = $this->splitMultilineValues($validated['aiPersonalityTraits'] ?? '');
        $identityProfile['values'] = $this->splitMultilineValues($validated['aiValues'] ?? '');
        $identityProfile['daily_routine'] = $this->nullableString($validated['aiDailyRoutine'] ?? null);
        $identityProfile['background_story'] = $this->nullableString($validated['aiBackgroundStory'] ?? null);

        $botProfile['communication_style'] = $this->nullableString($validated['aiCommunicationStyle'] ?? null);
        $botProfile['writing_style'] = $this->nullableString($validated['aiWritingStyle'] ?? null);
        $botProfile['behavior_guidelines'] = $this->nullableString($validated['aiBehaviorGuidelines'] ?? null);

        $this->personRecord->forceFill([
            'identity_profile' => $identityProfile,
            'bot_profile' => $botProfile,
        ])->save();

        $this->refreshProfileDetail();
        session()->flash('success', 'AI-Persona-Daten wurden gespeichert.');
    }

    public function generateActivitySimulation(PersonaActivityPlanner $planner): void
    {
        if (! $this->personRecord) {
            return;
        }

        $validated = $this->validate([
            'activitySimulationDays' => ['required', 'integer', 'min:1', 'max:14'],
            'activitySimulationIntensity' => ['required', 'string', 'in:quiet,balanced,active,creator'],
            'activitySimulationSeed' => ['nullable', 'string', 'max:120'],
        ]);

        $plan = $planner->build(
            person: $this->personRecord,
            days: (int) $validated['activitySimulationDays'],
            intensity: $validated['activitySimulationIntensity'],
            seed: $this->nullableString($validated['activitySimulationSeed'] ?? null),
        );

        $metadata = is_array($this->personRecord->metadata) ? $this->personRecord->metadata : [];
        $metadata['internal_activity_simulation'] = $plan;

        $this->personRecord->forceFill([
            'metadata' => $metadata,
        ])->save();

        $this->refreshProfileDetail();

        session()->flash('success', 'Interne Aktivitaets-Simulation wurde erstellt.');
    }

    public function clearActivitySimulation(): void
    {
        if (! $this->personRecord) {
            return;
        }

        $metadata = is_array($this->personRecord->metadata) ? $this->personRecord->metadata : [];
        unset($metadata['internal_activity_simulation']);

        $this->personRecord->forceFill([
            'metadata' => $metadata,
        ])->save();

        $this->refreshProfileDetail();

        session()->flash('success', 'Interne Aktivitaets-Simulation wurde entfernt.');
    }

    public function uploadAvatar(): void
    {
        if (! $this->personRecord) {
            return;
        }

        $validated = $this->validate([
            'avatarUpload' => ['required', 'image', 'max:4096'],
        ]);

        $upload = $validated['avatarUpload'];
        $path = $upload->store('uploads/person-avatars', 'private');
        $mime = Storage::disk('private')->mimeType($path) ?? $upload->getMimeType();

        $this->personRecord->files()
            ->where('type', 'avatar')
            ->get()
            ->each
            ->delete();

        $this->personRecord->files()->create([
            'filepool_id' => $this->personRecord->filePool?->id,
            'user_id' => auth()->id(),
            'name' => pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Profilbild',
            'path' => $path,
            'disk' => 'private',
            'mime_type' => $mime,
            'type' => 'avatar',
            'size' => $upload->getSize(),
        ]);

        $this->personRecord->forceFill([
            'avatar_path' => $path,
        ])->save();

        $this->avatarUpload = null;
        $this->refreshProfileDetail();
        session()->flash('success', 'Profilbild wurde aktualisiert.');
    }

    public function deleteAvatar(): void
    {
        if (! $this->personRecord) {
            return;
        }

        $avatarPath = $this->personRecord->avatar_path;

        $this->personRecord->files()
            ->where('type', 'avatar')
            ->get()
            ->each
            ->delete();

        if ($avatarPath && ! $this->pathBelongsToExistingFile($avatarPath) && Storage::disk('private')->exists($avatarPath)) {
            Storage::disk('private')->delete($avatarPath);
        }

        $this->personRecord->forceFill([
            'avatar_path' => null,
        ])->save();

        $this->refreshProfileDetail();
        $this->dispatch('refreshFilePool');

        session()->flash('success', 'Profilbild wurde geloescht.');
    }

    public function useImageAsAvatar(int $fileId): void
    {
        if (! $this->personRecord) {
            return;
        }

        $file = File::query()->findOrFail($fileId);

        if (! $this->personOwnsFile($file) || ! $file->is_image) {
            $this->dispatch('showAlert', 'Dieses Bild gehoert nicht zu dieser Person.', 'error');

            return;
        }

        $this->personRecord->files()
            ->where('type', 'avatar')
            ->get()
            ->each
            ->delete();

        $this->createPersonAvatarFileFrom($file);

        $this->refreshProfileDetail();
        $this->dispatch('refreshFilePool');

        session()->flash('success', 'Bild wurde als Profilbild gesetzt.');
    }

    public function deleteImageFile(int $fileId): void
    {
        if (! $this->personRecord) {
            return;
        }

        $file = File::query()->findOrFail($fileId);

        if (! $this->personOwnsFile($file) || ! $file->is_image) {
            $this->dispatch('showAlert', 'Dieses Bild gehoert nicht zu dieser Person.', 'error');

            return;
        }

        if ($file->type === 'avatar' || $this->personRecord->avatar_path === $file->path) {
            $this->personRecord->forceFill([
                'avatar_path' => null,
            ])->save();
        }

        $file->delete();

        $this->refreshProfileDetail();
        $this->dispatch('refreshFilePool');

        session()->flash('success', 'Bild wurde geloescht.');
    }

    protected function refreshProfileDetail(): void
    {
        $collection = $this->loadProfileCollection();
        $profile = $this->findProfile($collection, $this->profileId);

        if (! $profile) {
            $this->profileId = $collection['active_profile_id'];
            $profile = $this->findProfile($collection, $this->profileId);
        }

        $this->activeProfileId = $this->profileId;
        $this->activeProfileIds = $collection['active_profile_ids'];
        $this->profileOptions = $this->buildProfileOptions($collection);
        $this->profileDetail = collect($this->profileOptions)
            ->firstWhere('id', $this->profileId) ?? [];
        $this->personRecord = Person::query()
            ->where('profile_key', $this->profileId)
            ->first();

        $this->hydrateAvatar();
        $this->loadImageFiles();
        $this->hydrateAiProfile();
        $this->loadActivitySimulationState();

        if ($profile) {
            $this->fillFormFromProfile($profile);
        }
    }

    protected function hydrateAvatar(): void
    {
        $this->avatarFile = null;
        $this->avatarUrl = '';

        if (! $this->personRecord) {
            return;
        }

        $this->avatarFile = $this->personRecord->files()
            ->where('type', 'avatar')
            ->latest('id')
            ->first();

        if (! $this->avatarFile && $this->personRecord->avatar_path) {
            $this->avatarFile = $this->createPersonAvatarFileFromPath($this->personRecord->avatar_path);
        }

        if ($this->avatarFile) {
            $this->avatarUrl = $this->avatarFile->getEphemeralPublicUrl(10);

            return;
        }

        if ($this->personRecord->avatar_path && Storage::disk('private')->exists($this->personRecord->avatar_path)) {
            $temporaryAvatar = new File([
                'path' => $this->personRecord->avatar_path,
                'disk' => 'private',
                'mime_type' => Storage::disk('private')->mimeType($this->personRecord->avatar_path),
            ]);
            $temporaryAvatar->id = 0;
            $this->avatarUrl = $temporaryAvatar->getEphemeralPublicUrl(10);
        }
    }

    protected function loadImageFiles(): void
    {
        $this->imageFiles = [];

        if (! $this->personRecord) {
            return;
        }

        $this->personRecord->loadMissing('filePool');

        $files = collect($this->personRecord->files()
            ->where('mime_type', 'like', 'image/%')
            ->latest('id')
            ->get());

        if ($this->personRecord->filePool) {
            $files = $files->merge($this->personRecord->filePool->files()
                ->where('mime_type', 'like', 'image/%')
                ->latest('id')
                ->get());
        }

        $avatarFileId = $this->avatarFile?->id;
        $avatarPath = $this->personRecord->avatar_path;

        $this->imageFiles = $files
            ->filter(fn (File $file): bool => $file->is_image && ! $file->isExpired())
            ->reject(fn (File $file): bool => $file->id === $avatarFileId || ($avatarPath && $file->path === $avatarPath))
            ->unique('id')
            ->map(fn (File $file): array => [
                'id' => $file->id,
                'name' => $file->name_with_extension,
                'type' => $file->type,
                'size' => $file->size_formatted,
                'url' => $file->getEphemeralPublicUrl(10),
            ])
            ->values()
            ->toArray();
    }

    protected function createPersonAvatarFileFrom(File $sourceFile): File
    {
        $this->personRecord->loadMissing('filePool');

        $avatarFile = $this->personRecord->files()->create([
            'filepool_id' => $this->personRecord->filePool?->id,
            'user_id' => auth()->id() ?: $sourceFile->user_id,
            'name' => $sourceFile->name ?: 'Profilbild',
            'path' => $sourceFile->path,
            'disk' => $sourceFile->disk ?: 'private',
            'mime_type' => $sourceFile->mime_type,
            'type' => 'avatar',
            'size' => $sourceFile->size,
        ]);

        $this->personRecord->forceFill([
            'avatar_path' => $avatarFile->path,
        ])->save();

        return $avatarFile;
    }

    protected function createPersonAvatarFileFromPath(string $path): ?File
    {
        $path = trim($path);

        if ($path === '' || ! Storage::disk('private')->exists($path)) {
            return null;
        }

        $sourceFile = File::query()
            ->where('disk', 'private')
            ->where('path', $path)
            ->latest('id')
            ->first();

        return $this->createPersonAvatarFileFrom($sourceFile ?: new File([
            'name' => 'Profilbild',
            'path' => $path,
            'disk' => 'private',
            'mime_type' => Storage::disk('private')->mimeType($path),
            'size' => Storage::disk('private')->size($path),
        ]));
    }

    protected function personOwnsFile(File $file): bool
    {
        if (! $this->personRecord) {
            return false;
        }

        if ($file->fileable_type === Person::class && (int) $file->fileable_id === (int) $this->personRecord->id) {
            return true;
        }

        $filePoolId = $this->personRecord->filePool?->id;

        return $filePoolId !== null
            && $file->fileable_type === \App\Models\FilePool::class
            && (int) $file->fileable_id === (int) $filePoolId;
    }

    protected function pathBelongsToExistingFile(string $path): bool
    {
        if (! $this->personRecord || trim($path) === '') {
            return false;
        }

        $this->personRecord->loadMissing('filePool');

        $query = File::query()->where('path', $path)->where(function ($query): void {
            $query->where(function ($query): void {
                $query->where('fileable_type', Person::class)
                    ->where('fileable_id', $this->personRecord->id);
            });

            if ($this->personRecord->filePool) {
                $query->orWhere(function ($query): void {
                    $query->where('fileable_type', \App\Models\FilePool::class)
                        ->where('fileable_id', $this->personRecord->filePool->id);
                });
            }
        });

        return $query->exists();
    }

    protected function hydrateAiProfile(): void
    {
        $identityProfile = is_array($this->personRecord?->identity_profile) ? $this->personRecord->identity_profile : [];
        $botProfile = is_array($this->personRecord?->bot_profile) ? $this->personRecord->bot_profile : [];

        $this->aiNationality = (string) ($identityProfile['nationality'] ?? '');
        $this->aiOccupation = (string) ($identityProfile['occupation'] ?? '');
        $this->aiRelationshipStatus = (string) ($identityProfile['relationship_status'] ?? '');
        $this->aiLanguages = implode(PHP_EOL, $this->normalizeStringList($identityProfile['languages'] ?? []));
        $this->aiInterests = implode(PHP_EOL, $this->normalizeStringList($identityProfile['interests'] ?? []));
        $this->aiPersonalityTraits = implode(PHP_EOL, $this->normalizeStringList($identityProfile['personality_traits'] ?? []));
        $this->aiValues = implode(PHP_EOL, $this->normalizeStringList($identityProfile['values'] ?? []));
        $this->aiCommunicationStyle = (string) ($botProfile['communication_style'] ?? '');
        $this->aiWritingStyle = (string) ($botProfile['writing_style'] ?? '');
        $this->aiDailyRoutine = (string) ($identityProfile['daily_routine'] ?? '');
        $this->aiBackgroundStory = (string) ($identityProfile['background_story'] ?? '');
        $this->aiBehaviorGuidelines = (string) ($botProfile['behavior_guidelines'] ?? '');
    }

    protected function loadActivitySimulationState(): void
    {
        $this->activitySimulation = [];
        $this->activitySimulationDays = 7;
        $this->activitySimulationIntensity = 'balanced';
        $this->activitySimulationSeed = '';

        $metadata = is_array($this->personRecord?->metadata) ? $this->personRecord->metadata : [];
        $simulation = $metadata['internal_activity_simulation'] ?? [];

        if (! is_array($simulation) || $simulation === []) {
            return;
        }

        $this->activitySimulation = $simulation;
        $this->activitySimulationDays = max(1, min(14, (int) ($simulation['days'] ?? 7)));
        $this->activitySimulationIntensity = in_array(($simulation['intensity'] ?? ''), ['quiet', 'balanced', 'active', 'creator'], true)
            ? (string) $simulation['intensity']
            : 'balanced';
        $this->activitySimulationSeed = trim((string) ($simulation['seed'] ?? ''));
    }

    protected function splitMultilineValues(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            preg_split('/[\r\n,;]+/', $value) ?: []
        )));
    }

    protected function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        )));
    }
}
