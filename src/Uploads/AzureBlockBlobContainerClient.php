<?php

namespace Sopamo\LaravelFilepond\Uploads;

interface AzureBlockBlobContainerClient
{
    public function getBlockBlobClient(string $path): AzureBlockBlobClient;
}
