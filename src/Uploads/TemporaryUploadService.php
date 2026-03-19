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
        private readonly FilepondConfiguration $configuration,
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
            $this->configuration->temporaryFilesDisk()
        );

        if (!$storedFile) {
            return null;
        }

        return $this->serverIdCodec->encode($storedFile);
    }

    /**
     * @param array<int, string>|string|null $uploadName
     */
    public function initializeChunkUpload(array|string|null $uploadName): string
    {
        $fileLocation = $this->uploadPathResolver->buildChunkInitializationPath($uploadName);

        return $this->serverIdCodec->encode($fileLocation);
    }

    public function deleteByServerId(string $serverId): bool
    {
        $filePath = $this->serverIdPathResolver->resolvePath($serverId);
        $storage = $this->temporaryStorage();

        $temporaryDirectoryDeleted = $storage->deleteDirectory(dirname($filePath));
        $chunkDirectoryDeleted = $this->deleteDirectoryIfItExists($storage, $this->uploadPathResolver->chunkStoragePath($filePath));

        return $temporaryDirectoryDeleted && $chunkDirectoryDeleted;
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
        $storage = $this->storageManager->disk($this->configuration->temporaryFilesDisk());
        if (!$storage instanceof FilesystemAdapter) {
            throw new \RuntimeException('Could not resolve the temporary upload storage.');
        }

        return $storage;
    }
}
