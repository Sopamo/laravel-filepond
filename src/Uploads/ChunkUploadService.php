<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Config;
use Sopamo\LaravelFilepond\Exceptions\IncompleteUploadException;

class ChunkUploadService
{
    public function __construct(
        private readonly FilesystemManager $storageManager,
        private readonly UploadPathResolver $paths
    ) {
    }

    public function store(ChunkUploadRequest $chunkUploadRequest, string $content): void
    {
        $storage = $this->storageManager->disk(Config::string('filepond.temporary_files_disk'));
        if (!$storage instanceof FilesystemAdapter) {
            throw new \RuntimeException('Could not resolve the temporary upload storage.');
        }

        $isTerminalEmptyChunk = $chunkUploadRequest->isTerminalEmptyChunk($content);
        if ($isTerminalEmptyChunk || $chunkUploadRequest->isLastDataChunk($content)) {
            // Only inspect the final file at completion boundaries. A completed
            // upload can acknowledge a retry without recreating chunks or manifests.
            $filePath = $chunkUploadRequest->finalFilePath();
            $isComplete = $storage->fileExists($filePath)
                && $storage->size($filePath) === $chunkUploadRequest->length();
            if ($isComplete) {
                return;
            }

            // FilePond sends an empty terminal PATCH for exact chunk-size multiples.
            // That request has no data with which to complete an unfinished upload.
            if ($isTerminalEmptyChunk) {
                throw new IncompleteUploadException('The uploaded file is incomplete or no longer available.');
            }
        }

        $azure = AzureBlockBlobStorage::fromFilesystem($storage);
        $handler = $azure === null
            ? new FilesystemChunkWriteHandler($storage, $this->paths)
            : new AzureBlockBlobChunkWriteHandler($storage, $this->paths, $azure);

        $handler->store($chunkUploadRequest, $content);
    }
}
