<?php

namespace Sopamo\LaravelFilepond\Uploads;

interface AzurePathPrefixer
{
    public function prefixPath(string $path): string;
}
