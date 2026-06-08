<?php

namespace App\Livewire\Tools\FilePools;

use App\Models\File;
use App\Models\FilePool;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ManageFilePools extends Component
{
    use WithFileUploads;

    public string $modelType;

    public int $modelId;

    public ?int $filePoolId = null;

    public ?FilePool $filePool = null;

    public array $fileUploads = [];

    public array $expires = [];

    public ?File $file = null;

    public string $selectedFileName = '';

    public string $selectedFileExpiresDate = '';

    public bool $openFileForm = false;

    public bool $openEditFileForm = false;

    public bool $readOnly = true;

    public function mount(string $modelType, int $modelId, bool $readOnly = true): void
    {
        $this->modelType = $modelType;
        $this->modelId = $modelId;
        $this->readOnly = $readOnly;

        $model = $modelType::findOrFail($modelId);
        $this->filePool = $model->filePool()->firstOrCreate([
            'title' => 'Standard Ordner',
            'type' => class_basename($modelType),
            'description' => '',
        ]);
        $this->filePoolId = $this->filePool->id;
        $this->fileUploads = [$this->filePool->id => []];
    }

    public function uploadFile(int $filePoolId): void
    {
        $this->validate([
            "fileUploads.$filePoolId" => ['required', 'array', 'min:1'],
            "fileUploads.$filePoolId.*" => ['file', 'max:302400'],
            "expires.$filePoolId" => ['nullable', 'date', 'after:today'],
        ]);

        foreach ($this->fileUploads[$filePoolId] as $uploadedFile) {
            $filename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Datei';
            $path = $uploadedFile->store('uploads/files', 'private');
            $mime = Storage::disk('private')->mimeType($path) ?? $uploadedFile->getMimeType();

            $this->filePool->files()->create([
                'user_id' => Auth::id(),
                'name' => $filename,
                'path' => $path,
                'disk' => 'private',
                'mime_type' => $mime,
                'type' => 'default',
                'size' => $uploadedFile->getSize(),
                'expires_at' => $this->expires[$filePoolId] ?? null,
            ]);
        }

        unset($this->fileUploads[$filePoolId], $this->expires[$filePoolId]);
        $this->fileUploads[$filePoolId] = [];
        $this->openFileForm = false;
        $this->filePool->refresh();
    }

    public function downloadFile(int $fileId): StreamedResponse
    {
        $file = File::findOrFail($fileId);

        return $file->download($file->disk ?: 'private');
    }

    public function editFile(int $id): void
    {
        $this->file = File::findOrFail($id);
        $this->selectedFileName = $this->file->name;
        $this->selectedFileExpiresDate = optional($this->file->expires_at)?->format('Y-m-d') ?? '';
        $this->openEditFileForm = true;
    }

    public function saveFile(): void
    {
        $this->validate([
            'selectedFileName' => ['required', 'string', 'max:255'],
            'selectedFileExpiresDate' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        if (! $this->file) {
            return;
        }

        $this->file->update([
            'name' => trim($this->selectedFileName),
            'expires_at' => $this->selectedFileExpiresDate ?: null,
        ]);

        $this->reset(['file', 'selectedFileName', 'selectedFileExpiresDate', 'openEditFileForm']);
        $this->filePool->refresh();
    }

    public function deleteFile(int $fileId): void
    {
        $file = File::findOrFail($fileId);
        $file->delete();
        $this->filePool->refresh();
    }

    #[On('refreshFilePool')]
    public function refreshFilePool(): void
    {
        $this->filePool?->refresh();
    }

    public function downloadAll()
    {
        if (! $this->filePool) {
            abort(404, 'FilePool nicht gefunden.');
        }

        return $this->buildZipResponse(
            'AiUserFactory_Files_'.now()->format('Ymd_His'),
            $this->filePool->files()->get()
        );
    }

    protected function buildZipResponse(string $baseName, iterable $files)
    {
        $zipFileName = trim($baseName).'.zip';
        $zipDir = storage_path('app/private/zips');
        $zipPath = $zipDir.DIRECTORY_SEPARATOR.$zipFileName;

        if (! is_dir($zipDir)) {
            mkdir($zipDir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'ZIP konnte nicht erzeugt werden.');
        }

        $countAdded = 0;

        foreach ($files as $file) {
            if ($file->expires_at && now()->isAfter($file->expires_at)) {
                continue;
            }

            $disk = $file->disk ?: 'private';
            $absolutePath = Storage::disk($disk)->path($file->path);

            if (is_file($absolutePath) && is_readable($absolutePath)) {
                $zip->addFile($absolutePath, $file->name_with_extension);
                $countAdded++;
            }
        }

        $zip->close();

        if ($countAdded === 0) {
            @unlink($zipPath);
            abort(404, 'Keine herunterladbaren Dateien gefunden.');
        }

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    public function render()
    {
        $this->filePool = FilePool::query()
            ->where('filepoolable_type', $this->modelType)
            ->where('filepoolable_id', $this->modelId)
            ->first();

        return view('livewire.tools.file-pools.manage-file-pools', [
            'filePool' => $this->filePool,
        ]);
    }
}
