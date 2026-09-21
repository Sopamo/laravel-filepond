<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Sopamo\LaravelFilepond\ServerIdCodec;

class TemporaryUploadService
{
    public function __construct(
        private readonly FilesystemManager $storageManager,
        private readonly UploadPathResolver $uploadPathResolver,
        private readonly ServerIdPathResolver $serverIdPathResolver,
        private readonly ServerIdCodec $serverIdCodec
    ) {
    }

    public function storeUploadedFile(UploadedFile $file): ?string
    {
        $originalName = $file->getClientOriginalName();
        $targetPath = $this->uploadPathResolver->buildSingleUploadPath($originalName);
        $directory = dirname($targetPath);
        $filename = basename($targetPath);
        $disk = Config::string('filepond.temporary_files_disk');
        $storedFile = $file->storeAs($directory, $filename, $disk);

        if (!$storedFile) {
            return null;
        }

        return $this->serverIdCodec->encode($storedFile);
    }

    /**
     * @param array<int, string>|string|null $uploadName
     */
    public function initializeChunkUpload(array|string|null $uploadName, ?string $contentType = null): string
    {
        $fileLocation = $this->uploadPathResolver->buildChunkInitializationPath($uploadName);
        $this->storeAzureContentType($fileLocation, $contentType);

        return $this->serverIdCodec->encode($fileLocation);
    }

    private function storeAzureContentType(string $filePath, ?string $contentType): void
    {
        if ($contentType === null) {
            return;
        }

        // A process header callback may supply the file MIME type. The usual
        // multipart metadata request's type is not the uploaded file's type.
        $mediaType = explode(';', $contentType, 2)[0];
        $mediaType = trim($mediaType);
        $mediaType = strtolower($mediaType);
        if ($mediaType === '' || str_starts_with($mediaType, 'multipart/')
            || $mediaType === 'application/x-www-form-urlencoded') {
            return;
        }

        $storage = $this->temporaryStorage();
        if (AzureBlockBlobStorage::fromFilesystem($storage) === null) {
            return;
        }

        $manifestPath = $this->uploadPathResolver->azureManifestPath($filePath);
        $manifest = AzureChunkManifest::empty($mediaType);
        $manifestJson = $manifest->toJson();
        if (!$storage->put($manifestPath, $manifestJson)) {
            throw new \RuntimeException('Could not persist the Azure upload content type.');
        }
    }

    public function deleteByServerId(string $serverId): bool
    {
        $filePath = $this->serverIdPathResolver->resolvePath($serverId);
        $storage = $this->temporaryStorage();

        // Historical IDs can point directly into the shared temporary root.
        // Delete the file first and remove its parent only when it is empty.
        $fileDeleted = $storage->delete($filePath) || !$storage->exists($filePath);
        $chunkDirectory = $this->uploadPathResolver->chunkStoragePath($filePath);
        $chunkDirectoryDeleted = $this->deleteDirectoryIfItExists($storage, $chunkDirectory);

        $directory = dirname($filePath);
        $temporaryRoot = Config::string('filepond.temporary_files_path');
        $temporaryRoot = rtrim($temporaryRoot, '/');
        if ($fileDeleted && $directory !== $temporaryRoot && $storage->allFiles($directory) === []) {
            $storage->deleteDirectory($directory);
        }

        return $fileDeleted && $chunkDirectoryDeleted;
    }

    private function deleteDirectoryIfItExists(FilesystemAdapter $storage, string $directoryPath): bool
    {
        if ($storage->deleteDirectory($directoryPath)) {
            return true;
        }

        return !$storage->exists($directoryPath);
    }

    private function temporaryStorage(): FilesystemAdapter
    {
        $disk = Config::string('filepond.temporary_files_disk');
        $storage = $this->storageManager->disk($disk);
        if (!$storage instanceof FilesystemAdapter) {
            throw new \RuntimeException('Could not resolve the temporary upload storage.');
        }

        return $storage;
    }
}
