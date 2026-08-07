<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Sopamo\LaravelFilepond\Exceptions\UploadException;

class Filepond
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
        private readonly ServerIdCodec $serverIdCodec,
        private readonly ChunkAssembler $chunkAssembler,
    ) {
    }

    public function store(UploadedFile $file): string
    {
        $upload = $this->newUpload($file->getClientOriginalName());
        $storage = $this->storage($upload);
        $sourceStream = fopen($file->getRealPath(), 'rb');
        if ($sourceStream === false) {
            throw new UploadException('The uploaded file could not be read.', 500);
        }

        try {
            if (!$storage->put($upload->path, $sourceStream)) {
                throw new UploadException('The uploaded file could not be stored.', 500);
            }
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
        }

        return $this->serverIdCodec->encode($upload);
    }

    public function initializeChunkUpload(?string $originalName, ?int $length): string
    {
        $upload = $this->newUpload($originalName ?? 'upload');
        $this->chunkAssembler->initialize($upload, $length);

        return $this->serverIdCodec->encode($upload);
    }

    public function storeChunk(string $serverId, int $offset, int $length, string $content): int
    {
        return $this->chunkAssembler->store($this->resolveServerId($serverId), $offset, $length, $content);
    }

    public function revert(string $serverId): void
    {
        $upload = $this->resolveServerId($serverId);

        $this->chunkAssembler->withUploadLock($upload, function () use ($upload): void {
            $this->revertWhileLocked($upload);
        });
    }

    private function revertWhileLocked(TemporaryUpload $upload): void
    {
        $storage = $this->storage($upload);

        if ($upload->legacy) {
            $root = StoragePath::temporaryFilesRoot();
            $directory = str_replace('\\', '/', dirname($upload->path));
            if ($directory === $root) {
                $this->deleteFile($storage, $upload->path);
            } else {
                $this->deleteDirectory($storage, $directory);
            }
        } else {
            $this->deleteDirectory(
                $storage,
                StoragePath::temporaryFilesRoot().'/'.$upload->id,
            );
        }

        $this->deleteDirectory($storage, $this->chunkAssembler->chunkDirectory($upload));
    }

    public function resolveServerId(string $serverId): TemporaryUpload
    {
        return $this->serverIdCodec->decode($serverId);
    }

    /**
     * Resolve a FilePond server id to its temporary storage path.
     *
     * This method is retained for compatibility with version 2 consumers.
     */
    public function getPathFromServerId(string $serverId): string
    {
        return $this->resolveServerId($serverId)->path;
    }

    /**
     * Create a server id for an existing path below the configured temporary root.
     *
     * The encrypted-path format is retained for compatibility with version 2.
     */
    public function getServerIdFromPath(string $path): string
    {
        return $this->serverIdCodec->encodeLegacyPath($path);
    }

    private function newUpload(string $originalName): TemporaryUpload
    {
        StoragePath::assertDistinctRoots();
        $id = (string) Str::ulid();
        $originalName = $this->normalizeOriginalName($originalName);
        $root = StoragePath::temporaryFilesRoot();

        return new TemporaryUpload(
            $id,
            (string) config('filepond.temporary_files_disk', 'local'),
            $root.'/'.$id.'/'.$originalName,
            $originalName,
        );
    }

    private function normalizeOriginalName(string $originalName): string
    {
        $originalName = basename(str_replace('\\', '/', $originalName));

        if ($originalName === '' || $originalName === '.' || $originalName === '..'
            || str_contains($originalName, "\0") || strlen($originalName) > 255) {
            throw new UploadException('The original file name is invalid.', 422);
        }

        return $originalName;
    }

    private function storage(TemporaryUpload $upload): Filesystem
    {
        return $this->filesystems->disk($upload->disk);
    }

    private function deleteFile(Filesystem $storage, string $path): void
    {
        if (!$storage->delete($path)) {
            throw new UploadException('The temporary upload could not be deleted.', 500);
        }
    }

    private function deleteDirectory(Filesystem $storage, string $path): void
    {
        if (!$storage->deleteDirectory($path)) {
            throw new UploadException('The temporary upload directory could not be deleted.', 500);
        }
    }
}
