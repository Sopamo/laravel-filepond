<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use JsonException;
use Sopamo\LaravelFilepond\Exceptions\UploadException;

final class ChunkAssembler
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
        private readonly CacheManager $cache,
        private readonly ChunkFileAssembler $fileAssembler,
    ) {
    }

    public function initialize(TemporaryUpload $upload, ?int $length): void
    {
        StoragePath::assertDistinctRoots();

        if ($length !== null) {
            $this->assertUploadLength($length);
        }

        $completed = $length === 0;
        if ($completed && !$this->storage($upload)->put($upload->path, '')) {
            throw new UploadException('The empty upload could not be stored.', 500);
        }

        $this->writeManifest($upload, [
            'version' => 1,
            'path' => $upload->path,
            'length' => $length,
            'parts' => [],
            'completed' => $completed,
        ]);
    }

    public function store(TemporaryUpload $upload, int $offset, int $length, string $content): int
    {
        $this->assertUploadLength($length);
        $contentLength = strlen($content);

        if ($offset < 0 || $contentLength === 0 || $contentLength > (int) config('filepond.maximum_chunk_size')) {
            throw new UploadException('The chunk size or offset is invalid.', 422);
        }

        if ($offset > $length || $contentLength > $length - $offset) {
            throw new UploadException('The chunk exceeds the declared upload length.', 422);
        }

        return $this->withUploadLock(
            $upload,
            fn (): int => $this->storeWhileLocked($upload, $offset, $length, $content),
        );
    }

    /**
     * @template Result
     *
     * @param callable(): Result $callback
     * @return Result
     */
    public function withUploadLock(TemporaryUpload $upload, callable $callback): mixed
    {
        try {
            return $this->lock($upload)->block(
                (int) config('filepond.lock_wait_seconds', 5),
                $callback,
            );
        } catch (LockTimeoutException $exception) {
            throw new UploadException('The upload is currently being updated.', 423, $exception);
        }
    }

    public function manifestPath(TemporaryUpload $upload): string
    {
        return $this->chunkDirectory($upload).'/manifest.json';
    }

    public function chunkDirectory(TemporaryUpload $upload): string
    {
        return StoragePath::chunksRoot().'/'.$upload->id;
    }

    private function storeWhileLocked(TemporaryUpload $upload, int $offset, int $length, string $content): int
    {
        $manifest = $this->readManifest($upload);
        $contentLength = strlen($content);

        if ($manifest['length'] !== null && $manifest['length'] !== $length) {
            throw new UploadException('The upload length changed during upload.', 409);
        }

        if ($manifest['completed'] === true) {
            $this->storage($upload)->deleteDirectory($this->chunkDirectory($upload).'/parts');

            return $length;
        }

        $manifest['length'] = $length;
        $checksum = hash('sha256', $content);
        $existingPart = $manifest['parts'][$offset] ?? null;

        if ($existingPart !== null) {
            if ($existingPart !== ['size' => $contentLength, 'checksum' => $checksum]) {
                throw new UploadException('A different chunk already exists at this offset.', 409);
            }

            return $this->completeIfReady($upload, $manifest, $length);
        }

        $this->assertChunkRangeAvailable($manifest['parts'], $offset, $contentLength);

        $storage = $this->storage($upload);
        $partPath = $this->chunkDirectory($upload).'/parts/'.$offset;
        if (!$storage->put($partPath, $content)) {
            throw new UploadException('The chunk could not be stored.', 500);
        }

        $manifest['parts'][$offset] = ['size' => $contentLength, 'checksum' => $checksum];

        return $this->completeIfReady($upload, $manifest, $length);
    }

    /** @param array<string, mixed> $manifest */
    private function completeIfReady(TemporaryUpload $upload, array $manifest, int $length): int
    {
        $this->assertStoredPartRanges($manifest['parts'], $length);
        $contiguousOffset = $this->contiguousOffset($manifest['parts']);
        $storage = $this->storage($upload);

        if ($contiguousOffset === $length) {
            $this->fileAssembler->assemble(
                $storage,
                $upload,
                $this->chunkDirectory($upload),
                $manifest['parts'],
                $length,
            );
            $manifest['completed'] = true;
        }

        $this->writeManifest($upload, $manifest);

        if ($manifest['completed'] === true) {
            $storage->deleteDirectory($this->chunkDirectory($upload).'/parts');
        }

        return $contiguousOffset;
    }

    /** @param array<int, array{size: int, checksum: string}> $parts */
    private function contiguousOffset(array $parts): int
    {
        $offsets = array_map('intval', array_keys($parts));
        sort($offsets, SORT_NUMERIC);
        $nextOffset = 0;

        foreach ($offsets as $offset) {
            if ($offset !== $nextOffset) {
                break;
            }
            $nextOffset += $parts[$offset]['size'];
        }

        return $nextOffset;
    }

    /** @return array{version: int, path: string, length: ?int, parts: array<int, array{size: int, checksum: string}>, completed: bool} */
    private function readManifest(TemporaryUpload $upload): array
    {
        $storage = $this->storage($upload);
        $path = $this->manifestPath($upload);

        if (!$storage->exists($path)) {
            throw new UploadException('The chunk upload does not exist.', 404);
        }

        $manifestContents = $storage->get($path);
        if ($manifestContents === null) {
            throw new UploadException('The chunk upload state could not be read.', 500);
        }

        try {
            $manifest = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UploadException('The chunk upload state is invalid.', 500, $exception);
        }

        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1
            || ($manifest['path'] ?? null) !== $upload->path || !is_array($manifest['parts'] ?? null)
            || !is_bool($manifest['completed'] ?? null)
            || !array_key_exists('length', $manifest)
            || (!is_int($manifest['length']) && $manifest['length'] !== null)) {
            throw new UploadException('The chunk upload state is invalid.', 500);
        }

        foreach ($manifest['parts'] as $offset => $part) {
            if (!is_int($offset) || $offset < 0
                || !is_array($part)
                || !is_int($part['size'] ?? null) || $part['size'] <= 0
                || !is_string($part['checksum'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $part['checksum']) !== 1) {
                throw new UploadException('The chunk upload state is invalid.', 500);
            }
        }

        $this->assertStoredPartRanges($manifest['parts'], $manifest['length']);

        return $manifest;
    }

    /** @param array<int, array{size: int, checksum: string}> $parts */
    private function assertChunkRangeAvailable(array $parts, int $offset, int $size): void
    {
        $end = $offset + $size;

        foreach ($parts as $existingOffset => $part) {
            $existingStart = (int) $existingOffset;
            $existingEnd = $existingStart + $part['size'];

            if ($offset < $existingEnd && $existingStart < $end) {
                throw new UploadException('The chunk overlaps an existing chunk.', 409);
            }
        }
    }

    /** @param array<int, array{size: int, checksum: string}> $parts */
    private function assertStoredPartRanges(array $parts, ?int $length): void
    {
        if ($length !== null && $length < 0) {
            throw new UploadException('The chunk upload state contains invalid or overlapping ranges.', 500);
        }

        $offsets = array_map('intval', array_keys($parts));
        sort($offsets, SORT_NUMERIC);
        $previousEnd = 0;

        foreach ($offsets as $offset) {
            $size = $parts[$offset]['size'];
            if ($offset < $previousEnd || $offset > PHP_INT_MAX - $size
                || ($length !== null && ($offset > $length || $size > $length - $offset))) {
                throw new UploadException('The chunk upload state contains invalid or overlapping ranges.', 500);
            }

            $previousEnd = $offset + $size;
        }
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(TemporaryUpload $upload, array $manifest): void
    {
        if (!$this->storage($upload)->put($this->manifestPath($upload), json_encode($manifest, JSON_THROW_ON_ERROR))) {
            throw new UploadException('The chunk upload state could not be stored.', 500);
        }
    }

    private function assertUploadLength(int $length): void
    {
        if ($length < 0 || $length > (int) config('filepond.maximum_upload_size')) {
            throw new UploadException('The upload length is invalid.', 422);
        }
    }

    private function lock(TemporaryUpload $upload): Lock
    {
        $store = $this->cache->store(config('filepond.lock_store'))->getStore();
        if (!$store instanceof LockProvider) {
            throw new UploadException('The configured cache store does not support locks.', 500);
        }

        return $store->lock(
            'filepond:'.$upload->disk.':'.$upload->id,
            $this->lockSeconds(),
        );
    }

    private function lockSeconds(): int
    {
        $configuredLockSeconds = config('filepond.lock_seconds', 900);

        if (!is_int($configuredLockSeconds)
            && !(is_string($configuredLockSeconds) && ctype_digit($configuredLockSeconds))) {
            throw new UploadException('FILEPOND_LOCK_SECONDS must be an integer.', 500);
        }

        $lockSeconds = (int) $configuredLockSeconds;
        if ($lockSeconds <= 0) {
            throw new UploadException('FILEPOND_LOCK_SECONDS must be greater than zero.', 500);
        }

        return $lockSeconds;
    }

    private function storage(TemporaryUpload $upload): Filesystem
    {
        return $this->filesystems->disk($upload->disk);
    }
}
