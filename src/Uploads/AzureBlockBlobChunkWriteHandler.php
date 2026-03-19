<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Filesystem\FilesystemAdapter;

final class AzureBlockBlobChunkWriteHandler implements ChunkWriteHandler
{
    public function __construct(
        private readonly FilesystemAdapter $storage,
        private readonly UploadPathResolver $uploadPathResolver,
        private readonly AzureBlockBlobContainerClient $containerClient,
        private readonly AzurePathPrefixer $pathPrefixer
    ) {
    }

    public function store(ChunkUploadRequest $chunkUploadRequest, string $content): void
    {
        $blockBlobClient = $this->containerClient->getBlockBlobClient(
            $this->pathPrefixer->prefixPath($chunkUploadRequest->finalFilePath())
        );
        $blockId = $this->buildBlockId($chunkUploadRequest->offset());

        $blockBlobClient->stageBlock($blockId, $content);

        $manifestPath = $this->uploadPathResolver->azureManifestPath($chunkUploadRequest->finalFilePath());
        $manifest = $this->loadManifest($manifestPath)
            ->withUploadLength($chunkUploadRequest->length())
            ->withChunk(new ChunkPart($chunkUploadRequest->offset(), strlen($content), $blockId));

        if ($this->storage->put($manifestPath, $manifest->toJson()) === false) {
            throw new \RuntimeException('Could not persist the Azure block blob chunk upload manifest.');
        }

        $chunkCollection = $manifest->toChunkCollection();
        if (!$chunkCollection->isComplete($manifest->uploadLength())) {
            return;
        }

        $blockBlobClient->commitBlockList($chunkCollection->orderedReferences());
        $this->storage->deleteDirectory($this->uploadPathResolver->chunkStoragePath($chunkUploadRequest->finalFilePath()));
    }

    private function buildBlockId(int $offset): string
    {
        return base64_encode(str_pad((string) $offset, 20, '0', STR_PAD_LEFT));
    }

    private function loadManifest(string $manifestPath): AzureChunkManifest
    {
        if (!$this->storage->exists($manifestPath)) {
            return AzureChunkManifest::empty();
        }

        $manifestJson = $this->storage->get($manifestPath);
        if (!is_string($manifestJson)) {
            throw new \RuntimeException('Invalid Azure block blob chunk upload manifest.');
        }

        return AzureChunkManifest::fromJson($manifestJson);
    }
}
