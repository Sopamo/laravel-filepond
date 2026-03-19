<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Filesystem\FilesystemAdapter;

final class FilesystemChunkWriteHandler implements ChunkWriteHandler
{
    public function __construct(
        private readonly FilesystemAdapter $storage,
        private readonly UploadPathResolver $uploadPathResolver
    ) {
    }

    public function store(ChunkUploadRequest $chunkUploadRequest, string $content): void
    {
        $basePath = $this->uploadPathResolver->chunkStoragePath($chunkUploadRequest->finalFilePath());

        if ($this->storage->put(
            $basePath.DIRECTORY_SEPARATOR.'patch.'.$chunkUploadRequest->offset(),
            $content,
            ['mimetype' => 'application/octet-stream']
        ) === false) {
            throw new \RuntimeException('Could not store the uploaded chunk.');
        }

        $chunkCollection = $this->collectChunks($basePath);
        if (!$chunkCollection->isComplete($chunkUploadRequest->length())) {
            return;
        }

        $temporaryMergedFile = tmpfile();
        if ($temporaryMergedFile === false) {
            throw new \RuntimeException('Could not create a temporary file for chunk merging.');
        }

        try {
            foreach ($chunkCollection->orderedReferences() as $chunkPath) {
                $chunkStream = $this->storage->readStream($chunkPath);
                if (!is_resource($chunkStream)) {
                    throw new \RuntimeException('Could not open an uploaded chunk for reading.');
                }

                try {
                    stream_copy_to_stream($chunkStream, $temporaryMergedFile);
                } finally {
                    fclose($chunkStream);
                }
            }

            rewind($temporaryMergedFile);

            if ($this->storage->put($chunkUploadRequest->finalFilePath(), $temporaryMergedFile) === false) {
                throw new \RuntimeException('Could not persist the merged file.');
            }
        } finally {
            fclose($temporaryMergedFile);
        }

        $this->storage->deleteDirectory($basePath);
    }

    private function collectChunks(string $basePath): ChunkCollection
    {
        $parts = [];

        foreach ($this->storage->files($basePath) as $chunkPath) {
            $chunkOffset = $this->extractChunkOffset($chunkPath);
            if ($chunkOffset === null) {
                continue;
            }

            $parts[] = new ChunkPart(
                $chunkOffset,
                $this->storage->size($chunkPath),
                $chunkPath
            );
        }

        return new ChunkCollection($parts);
    }

    private function extractChunkOffset(string $chunkPath): ?int
    {
        $matches = [];

        if (!preg_match('/^patch\.(\d+)$/', basename($chunkPath), $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
