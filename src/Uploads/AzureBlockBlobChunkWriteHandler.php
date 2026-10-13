<?php

namespace Sopamo\LaravelFilepond\Uploads;

use AzureOss\Storage\Blob\Models\BlobHttpHeaders;
use AzureOss\Storage\Blob\Models\CommitBlockListOptions;
use Illuminate\Filesystem\FilesystemAdapter;

final class AzureBlockBlobChunkWriteHandler implements ChunkWriteHandler
{
    public function __construct(
        private readonly FilesystemAdapter $storage,
        private readonly UploadPathResolver $uploadPathResolver,
        private readonly AzureBlockBlobStorage $azure
    ) {
    }

    public function store(ChunkUploadRequest $chunkUploadRequest, string $content): void
    {
        $filePath = $chunkUploadRequest->finalFilePath();
        $offset = $chunkUploadRequest->offset();
        $uploadLength = $chunkUploadRequest->length();
        $blockBlobClient = $this->azure->client($filePath);
        $blockId = $this->buildBlockId($offset);

        $manifestPath = $this->uploadPathResolver->azureManifestPath($filePath);
        $manifest = $this->loadManifest($manifestPath)->withUploadLength($uploadLength);

        if (!$chunkUploadRequest->isEmptyUpload($content)) {
            $blockBlobClient->stageBlock($blockId, $content);
            $part = new ChunkPart($offset, strlen($content), $blockId);
            $manifest = $manifest->withChunk($part);
        }

        if ($this->storage->put($manifestPath, $manifest->toJson()) === false) {
            throw new \RuntimeException('Could not persist the Azure block blob chunk upload manifest.');
        }

        $chunkCollection = $manifest->toChunkCollection();
        if (!$chunkCollection->isComplete($uploadLength)) {
            return;
        }

        $fileContentType = $manifest->fileContentType() ?? 'application/octet-stream';
        $options = new CommitBlockListOptions(
            new BlobHttpHeaders(contentType: $fileContentType)
        );
        $blockBlobClient->commitBlockList($chunkCollection->orderedReferences(), $options);

        $chunkDirectory = $this->uploadPathResolver->chunkStoragePath($filePath);
        $this->storage->deleteDirectory($chunkDirectory);
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
