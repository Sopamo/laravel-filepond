<?php

namespace Sopamo\LaravelFilepond\Uploads;

interface AzureBlockBlobClient
{
    public function stageBlock(string $blockId, string $content): void;

    /**
     * @param array<int, string> $blockIds
     */
    public function commitBlockList(array $blockIds): void;
}
