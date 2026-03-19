<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Support\Str;

class UploadPathResolver
{
    public function __construct(private readonly FilepondConfiguration $configuration)
    {
    }

    public function buildSingleUploadPath(string $originalName): string
    {
        return $this->configuration->temporaryFilesPath()
            .DIRECTORY_SEPARATOR.Str::random()
            .DIRECTORY_SEPARATOR.$originalName;
    }

    /**
     * @param array<int, string>|string|null $uploadName
     */
    public function buildChunkInitializationPath(array|string|null $uploadName): string
    {
        $uploadDirectory = Str::random();
        $normalizedUploadName = $this->normalizeUploadName($uploadName);

        if ($normalizedUploadName === '') {
            return $this->configuration->temporaryFilesPath()
                .DIRECTORY_SEPARATOR.$uploadDirectory
                .DIRECTORY_SEPARATOR.$uploadDirectory;
        }

        return $this->configuration->temporaryFilesPath()
            .DIRECTORY_SEPARATOR.$uploadDirectory
            .DIRECTORY_SEPARATOR.basename($normalizedUploadName);
    }

    public function chunkStoragePath(string $finalFilePath): string
    {
        return $this->configuration->chunksPath().DIRECTORY_SEPARATOR.sha1($finalFilePath);
    }

    public function azureManifestPath(string $finalFilePath): string
    {
        return $this->chunkStoragePath($finalFilePath).DIRECTORY_SEPARATOR.'manifest.json';
    }

    /**
     * @param array<int, string>|string|null $uploadName
     */
    private function normalizeUploadName(array|string|null $uploadName): string
    {
        if (is_array($uploadName)) {
            $uploadName = reset($uploadName);
        }

        if (!is_string($uploadName)) {
            return '';
        }

        return trim($uploadName);
    }
}
