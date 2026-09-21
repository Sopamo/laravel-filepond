<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Support\Str;

class UploadPathResolver
{
    public function buildSingleUploadPath(string $originalName): string
    {
        return rtrim((string) config('filepond.temporary_files_path', 'filepond'), '/\\')
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

        return rtrim((string) config('filepond.temporary_files_path', 'filepond'), '/\\')
            .DIRECTORY_SEPARATOR.$uploadDirectory
            .DIRECTORY_SEPARATOR.($normalizedUploadName === '' ? $uploadDirectory : basename($normalizedUploadName));
    }

    public function chunkStoragePath(string $finalFilePath): string
    {
        return (string) config('filepond.chunks_path', 'filepond'.DIRECTORY_SEPARATOR.'chunks')
            .DIRECTORY_SEPARATOR.sha1($finalFilePath);
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
