<?php

namespace Sopamo\LaravelFilepond\Uploads;

final class ReflectedAzureBlockBlobContainerClient implements AzureBlockBlobContainerClient
{
    public function __construct(private readonly mixed $containerClient)
    {
        if (!is_object($containerClient) || !method_exists($containerClient, 'getBlockBlobClient')) {
            throw new \RuntimeException('Could not resolve the Azure block blob client.');
        }
    }

    public function getBlockBlobClient(string $path): AzureBlockBlobClient
    {
        return new ReflectedAzureBlockBlobClient($this->containerClient->getBlockBlobClient($path));
    }
}
