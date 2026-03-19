<?php

namespace Sopamo\LaravelFilepond\Support;

class ChunkUploadAdapterResolver
{
    const TYPE_OTHER = 'other';

    const TYPE_LEGACY_AZURE = 'legacy_azure';

    const TYPE_AZURE_OSS = 'azure_oss';

    /**
     * @param mixed $storage
     * @return array<string, mixed>
     */
    public function resolve($storage): array
    {
        $adapter = $this->unwrapAdapter($this->extractAdapter($storage));

        if (!is_object($adapter)) {
            return [
                'type' => self::TYPE_OTHER,
                'adapter' => null,
            ];
        }

        if ($this->isLegacyAzureAdapter($adapter)) {
            return [
                'type' => self::TYPE_LEGACY_AZURE,
                'adapter' => $adapter,
                'client' => $this->readObjectProperty($adapter, 'client'),
                'container' => $this->readObjectProperty($adapter, 'container'),
            ];
        }

        if ($this->isAzureOssAdapter($adapter)) {
            return [
                'type' => self::TYPE_AZURE_OSS,
                'adapter' => $adapter,
                'container_client' => $this->readObjectProperty($adapter, 'containerClient'),
                'path_prefixer' => $this->readObjectProperty($adapter, 'prefixer'),
            ];
        }

        return [
            'type' => self::TYPE_OTHER,
            'adapter' => $adapter,
        ];
    }

    /**
     * @param mixed $storage
     * @return mixed
     */
    private function extractAdapter($storage)
    {
        if (is_object($storage) && method_exists($storage, 'getAdapter')) {
            $adapter = $storage->getAdapter();
            if (is_object($adapter)) {
                return $adapter;
            }
        }

        if (!is_object($storage) || !method_exists($storage, 'getDriver')) {
            return null;
        }

        $driver = $storage->getDriver();
        if (is_object($driver) && method_exists($driver, 'getAdapter')) {
            $adapter = $driver->getAdapter();
            if (is_object($adapter)) {
                return $adapter;
            }
        }

        return $this->readObjectProperty($driver, 'adapter');
    }

    /**
     * @param mixed $adapter
     * @return mixed
     */
    private function unwrapAdapter($adapter)
    {
        if (!is_object($adapter)) {
            return $adapter;
        }

        $visitedObjects = [];

        while (is_object($adapter)) {
            $objectHash = spl_object_hash($adapter);
            if (isset($visitedObjects[$objectHash])) {
                break;
            }

            $visitedObjects[$objectHash] = true;

            $innerAdapter = $this->readObjectProperty($adapter, 'innerAdapter');
            if (!is_object($innerAdapter)) {
                $innerAdapter = $this->readObjectProperty($adapter, 'adapter');
            }

            if (!is_object($innerAdapter)) {
                break;
            }

            $adapter = $innerAdapter;
        }

        return $adapter;
    }

    /**
     * @param mixed $adapter
     */
    private function isLegacyAzureAdapter($adapter): bool
    {
        return is_object($adapter) && is_a($adapter, 'Matthewbdaly\\LaravelAzureStorage\\AzureBlobStorageAdapter');
    }

    /**
     * @param mixed $adapter
     */
    private function isAzureOssAdapter($adapter): bool
    {
        if (!is_object($adapter)) {
            return false;
        }

        return is_a($adapter, 'AzureOss\\Storage\\BlobFlysystem\\AzureBlobStorageAdapter')
            || is_a($adapter, 'AzureOss\\FlysystemAzureBlobStorage\\AzureBlobStorageAdapter');
    }

    /**
     * @param mixed $object
     * @return mixed
     */
    private function readObjectProperty($object, string $propertyName)
    {
        if (!is_object($object)) {
            return null;
        }

        try {
            $reflectionClass = new \ReflectionObject($object);

            while ($reflectionClass !== false) {
                if ($reflectionClass->hasProperty($propertyName)) {
                    $property = $reflectionClass->getProperty($propertyName);
                    $property->setAccessible(true);

                    return $property->getValue($object);
                }

                $reflectionClass = $reflectionClass->getParentClass();
            }
        } catch (\ReflectionException $exception) {
            return null;
        }

        return null;
    }
}
