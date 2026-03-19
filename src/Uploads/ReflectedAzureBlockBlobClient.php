<?php

namespace Sopamo\LaravelFilepond\Uploads;

final class ReflectedAzureBlockBlobClient implements AzureBlockBlobClient
{
    public function __construct(private readonly mixed $client)
    {
        if (!is_object($client)
            || !method_exists($client, 'stageBlock')
            || !method_exists($client, 'commitBlockList')) {
            throw new \RuntimeException('Could not resolve the Azure block blob upload methods.');
        }
    }

    public function stageBlock(string $blockId, string $content): void
    {
        $this->client->stageBlock($blockId, $content);
    }

    /**
     * @param array<int, string> $blockIds
     */
    public function commitBlockList(array $blockIds): void
    {
        $this->client->commitBlockList($blockIds);
    }
}
