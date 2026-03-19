<?php

namespace Sopamo\LaravelFilepond\Support;

class AzureOssChunkUploadManifest
{
    /**
     * @var mixed
     */
    private $storage;

    /**
     * @var string
     */
    private $manifestPath;

    /**
     * @param mixed $storage
     */
    public function __construct($storage, string $manifestPath)
    {
        $this->storage = $storage;
        $this->manifestPath = $manifestPath;
    }

    public function recordChunk(int $offset, int $chunkSize, string $blockId, int $uploadLength): void
    {
        $manifest = $this->loadManifest();
        $manifest['upload_length'] = $uploadLength;
        $manifest['chunks'][(string) $offset] = [
            'offset' => $offset,
            'size' => $chunkSize,
            'block_id' => $blockId,
        ];

        $this->storage->put($this->manifestPath, json_encode($manifest));
    }

    public function hasReceivedAllBytes(): bool
    {
        $manifest = $this->loadManifest();

        return $this->uploadedChunkBytes($manifest) >= $manifest['upload_length'];
    }

    /**
     * @return string[]
     */
    public function orderedBlockIds(): array
    {
        $chunkEntries = array_values($this->loadManifest()['chunks']);

        usort($chunkEntries, function (array $leftChunk, array $rightChunk): int {
            return $leftChunk['offset'] <=> $rightChunk['offset'];
        });

        return array_map(function (array $chunkEntry): string {
            return $chunkEntry['block_id'];
        }, $chunkEntries);
    }

    public function delete(): void
    {
        $this->storage->deleteDirectory(dirname($this->manifestPath));
    }

    /**
     * @return array{
     *     upload_length: int,
     *     chunks: array<string, array{offset: int, size: int, block_id: string}>
     * }
     */
    private function loadManifest(): array
    {
        if (!$this->storage->exists($this->manifestPath)) {
            return [
                'upload_length' => 0,
                'chunks' => [],
            ];
        }

        $manifestContent = $this->storage->get($this->manifestPath);
        $manifest = json_decode($manifestContent, true);

        if (!is_array($manifest) || !isset($manifest['upload_length']) || !isset($manifest['chunks']) || !is_array($manifest['chunks'])) {
            throw new \RuntimeException('Invalid AzureOss chunk upload manifest.');
        }

        return $manifest;
    }

    /**
     * @param array{
     *     upload_length: int,
     *     chunks: array<string, array{offset: int, size: int, block_id: string}>
     * } $manifest
     */
    private function uploadedChunkBytes(array $manifest): int
    {
        $uploadedChunkBytes = 0;

        foreach ($manifest['chunks'] as $chunkEntry) {
            $uploadedChunkBytes += $chunkEntry['size'];
        }

        return $uploadedChunkBytes;
    }
}
