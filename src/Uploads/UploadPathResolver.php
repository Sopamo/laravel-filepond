<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class UploadPathResolver
{
    public function buildSingleUploadPath(string $originalName): string
    {
        $root = Config::string('filepond.temporary_files_path');
        $root = rtrim($root, '/\\');
        $uploadDirectory = Str::random();

        return $root.DIRECTORY_SEPARATOR.$uploadDirectory
            .DIRECTORY_SEPARATOR.$originalName;
    }

    /**
     * @param array<int, string>|string|null $uploadName
     */
    public function buildChunkInitializationPath(array|string|null $uploadName): string
    {
        $uploadDirectory = Str::random();
        $normalizedUploadName = $this->normalizeUploadName($uploadName);
        $filename = $normalizedUploadName === '' ? $uploadDirectory : basename($normalizedUploadName);
        $root = Config::string('filepond.temporary_files_path');
        $root = rtrim($root, '/\\');

        return $root.DIRECTORY_SEPARATOR.$uploadDirectory.DIRECTORY_SEPARATOR.$filename;
    }

    public function chunkStoragePath(string $finalFilePath): string
    {
        $root = Config::string('filepond.chunks_path');
        $uploadId = sha1($finalFilePath);

        return $root.DIRECTORY_SEPARATOR.$uploadId;
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
