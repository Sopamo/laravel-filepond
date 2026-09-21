<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Config;

class ChunkUploadService
{
    public function __construct(
        private readonly FilesystemManager $storageManager,
        private readonly UploadPathResolver $paths
    ) {
    }

    public function store(ChunkUploadRequest $chunkUploadRequest, string $content): void
    {
        // FilePond sends an empty terminal PATCH for exact chunk-size multiples.
        // Completion already happened on the last data chunk; there is no part to store.
        if ($chunkUploadRequest->isTerminalEmptyChunk($content)) {
            return;
        }

        $disk = Config::string('filepond.temporary_files_disk');
        $storage = $this->storageManager->disk($disk);
        if (!$storage instanceof FilesystemAdapter) {
            throw new \RuntimeException('Could not resolve the temporary upload storage.');
        }

        $azure = AzureBlockBlobStorage::fromFilesystem($storage);
        $handler = $azure === null
            ? new FilesystemChunkWriteHandler($storage, $this->paths)
            : new AzureBlockBlobChunkWriteHandler($storage, $this->paths, $azure);

        $handler->store($chunkUploadRequest, $content);
    }
}
