<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Filesystem\FilesystemAdapter;

final class AzureBlockBlobChunkWriteHandlerFactory
{
    public function __construct(private readonly UploadPathResolver $uploadPathResolver)
    {
    }

    public function create(FilesystemAdapter $storage): ?AzureBlockBlobChunkWriteHandler
    {
        $storageAdapter = $this->extractStorageAdapter($storage);

        $innerAdapter = $this->readObjectProperty($storageAdapter, 'innerAdapter');
        if (is_object($innerAdapter)) {
            $storageAdapter = $innerAdapter;
        }

        if (!$this->isAzureBlockBlobAdapter($storageAdapter)) {
            return null;
        }

        $containerClient = $this->readObjectProperty($storageAdapter, 'containerClient');
        $pathPrefixer = $this->readObjectProperty($storageAdapter, 'prefixer');

        return new AzureBlockBlobChunkWriteHandler(
            $storage,
            $this->uploadPathResolver,
            new ReflectedAzureBlockBlobContainerClient($containerClient),
            new ReflectedAzurePathPrefixer($pathPrefixer)
        );
    }

    private function extractStorageAdapter(FilesystemAdapter $storage): mixed
    {
        return $storage->getAdapter();
    }

    private function isAzureBlockBlobAdapter(mixed $adapter): bool
    {
        if (!is_object($adapter)) {
            return false;
        }

        return is_a($adapter, 'AzureOss\\Storage\\BlobFlysystem\\AzureBlobStorageAdapter')
            || is_a($adapter, 'AzureOss\\FlysystemAzureBlobStorage\\AzureBlobStorageAdapter');
    }

    private function readObjectProperty(mixed $subject, string $propertyName): mixed
    {
        if (!is_object($subject)) {
            return null;
        }

        try {
            $reflectionClass = new \ReflectionObject($subject);

            while ($reflectionClass !== false) {
                if ($reflectionClass->hasProperty($propertyName)) {
                    $property = $reflectionClass->getProperty($propertyName);
                    $property->setAccessible(true);

                    return $property->getValue($subject);
                }

                $reflectionClass = $reflectionClass->getParentClass();
            }
        } catch (\ReflectionException) {
            return null;
        }

        return null;
    }
}
