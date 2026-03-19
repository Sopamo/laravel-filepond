<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;

class ChunkUploadService
{
    public function __construct(
        private readonly FilesystemManager $storageManager,
        private readonly FilepondConfiguration $configuration,
        private readonly ChunkWriteHandlerResolver $chunkWriteHandlerResolver
    ) {
    }

    public function store(ChunkUploadRequest $chunkUploadRequest, string $content): void
    {
        $storage = $this->temporaryStorage();

        $this->chunkWriteHandlerResolver->resolve($storage)->store($chunkUploadRequest, $content);
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
