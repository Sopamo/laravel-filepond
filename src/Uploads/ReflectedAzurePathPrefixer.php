<?php

namespace Sopamo\LaravelFilepond\Uploads;

final class ReflectedAzurePathPrefixer implements AzurePathPrefixer
{
    public function __construct(private readonly mixed $pathPrefixer)
    {
        if (!is_object($pathPrefixer) || !method_exists($pathPrefixer, 'prefixPath')) {
            throw new \RuntimeException('Could not resolve the Azure block blob path prefixer.');
        }
    }

    public function prefixPath(string $path): string
    {
        return (string) $this->pathPrefixer->prefixPath($path);
    }
}
