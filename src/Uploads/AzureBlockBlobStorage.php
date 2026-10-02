<?php

namespace Sopamo\LaravelFilepond\Uploads;

use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\Blob\Specialized\BlockBlobClient;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;

/**
 * The optional Azure OSS adapter does not expose its client or prefixer.
 * Keep that dependency on its internals here, away from the upload algorithm.
 */
final class AzureBlockBlobStorage
{
    private function __construct(
        private readonly BlobContainerClient $containerClient,
        private readonly PathPrefixer $prefixer
    ) {
    }

    public static function fromFilesystem(FilesystemAdapter $storage): ?self
    {
        $adapter = $storage->getAdapter();
        $innerAdapter = self::property($adapter, 'innerAdapter');
        if (is_object($innerAdapter)) {
            $adapter = $innerAdapter;
        }

        if (!is_a($adapter, 'AzureOss\\Storage\\BlobFlysystem\\AzureBlobStorageAdapter')
            && !is_a($adapter, 'AzureOss\\FlysystemAzureBlobStorage\\AzureBlobStorageAdapter')) {
            return null;
        }

        $client = self::property($adapter, 'containerClient');
        $prefixer = self::property($adapter, 'prefixer');
        if (!$client instanceof BlobContainerClient || !$prefixer instanceof PathPrefixer) {
            throw new \RuntimeException('Could not resolve the Azure block blob client and path prefixer.');
        }

        return new self($client, $prefixer);
    }

    public function client(string $path): BlockBlobClient
    {
        return $this->containerClient->getBlockBlobClient($this->prefixer->prefixPath($path));
    }

    private static function property(object $object, string $name): mixed
    {
        $reflection = new \ReflectionObject($object);

        do {
            if ($reflection->hasProperty($name)) {
                return $reflection->getProperty($name)->getValue($object);
            }
        } while ($reflection = $reflection->getParentClass());

        return null;
    }
}
