<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Http\UploadedFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
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
        $targetPath = $this->uploadPathResolver->buildSingleUploadPath($file->getClientOriginalName());
        $storedFile = $file->storeAs(
            dirname($targetPath),
            basename($targetPath),
            (string) config('filepond.temporary_files_disk', 'local')
        );

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

        // A process header callback may supply the file MIME type. The usual
        // multipart metadata request's type is not the uploaded file's type.
        $contentType = strtolower(trim(explode(';', $contentType ?? '')[0]));
        if ($contentType !== '' && !str_starts_with($contentType, 'multipart/')
            && $contentType !== 'application/x-www-form-urlencoded') {
            $storage = $this->temporaryStorage();
            if (AzureBlockBlobStorage::fromFilesystem($storage) !== null
                && !$storage->put($this->uploadPathResolver->azureManifestPath($fileLocation),
                    AzureChunkManifest::empty($contentType)->toJson())) {
                throw new \RuntimeException('Could not persist the Azure upload content type.');
            }
        }

        return $this->serverIdCodec->encode($fileLocation);
    }

    public function deleteByServerId(string $serverId): bool
    {
        $filePath = $this->serverIdPathResolver->resolvePath($serverId);
        $storage = $this->temporaryStorage();

        // Historical IDs can point directly into the shared temporary root.
        // Delete the file first and remove its parent only when it is empty.
        $fileDeleted = $storage->delete($filePath) || !$storage->exists($filePath);
        $chunkDirectoryDeleted = $this->deleteDirectoryIfItExists($storage, $this->uploadPathResolver->chunkStoragePath($filePath));

        $directory = dirname($filePath);
        if ($fileDeleted && $directory !== rtrim((string) config('filepond.temporary_files_path', 'filepond'), '/')
            && $storage->allFiles($directory) === []) {
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
        $storage = $this->storageManager->disk((string) config('filepond.temporary_files_disk', 'local'));
        if (!$storage instanceof FilesystemAdapter) {
            throw new \RuntimeException('Could not resolve the temporary upload storage.');
        }

        return $storage;
    }
}
