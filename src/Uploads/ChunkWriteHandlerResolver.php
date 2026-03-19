<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Filesystem\FilesystemAdapter;

final class ChunkWriteHandlerResolver
{
    public function __construct(
        private readonly UploadPathResolver $uploadPathResolver,
        private readonly AzureBlockBlobChunkWriteHandlerFactory $azureBlockBlobChunkWriteHandlerFactory
    ) {
    }

    public function resolve(FilesystemAdapter $storage): ChunkWriteHandler
    {
        $azureHandler = $this->azureBlockBlobChunkWriteHandlerFactory->create($storage);
        if ($azureHandler !== null) {
            return $azureHandler;
        }

        return new FilesystemChunkWriteHandler($storage, $this->uploadPathResolver);
    }
}
