<?php

namespace Sopamo\LaravelFilepond;

use Sopamo\LaravelFilepond\Exceptions\UploadException;

final class StoragePath
{
    public static function temporaryFilesRoot(): string
    {
        return self::configuredRoot('temporary_files_path', 'filepond');
    }

    public static function chunksRoot(): string
    {
        return self::configuredRoot('chunks_path', 'filepond/chunks');
    }

    public static function assertDistinctRoots(): void
    {
        if (self::temporaryFilesRoot() === self::chunksRoot()) {
            throw new UploadException('The filepond temporary and chunk storage paths must be different.', 500);
        }
    }

    private static function configuredRoot(string $configurationKey, string $default): string
    {
        $configuredRoot = config('filepond.'.$configurationKey, $default);

        if (!is_string($configuredRoot)) {
            throw new UploadException("The filepond {$configurationKey} configuration must be a string.", 500);
        }

        $normalizedRoot = str_replace('\\', '/', $configuredRoot);
        if (trim($normalizedRoot) === '' || str_starts_with($normalizedRoot, '/')
            || preg_match('/^[a-zA-Z]:\//', $normalizedRoot) === 1) {
            throw new UploadException("The filepond {$configurationKey} configuration must be a non-empty relative storage path.", 500);
        }

        $normalizedRoot = rtrim($normalizedRoot, '/');
        foreach (explode('/', $normalizedRoot) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                throw new UploadException("The filepond {$configurationKey} configuration contains an unsafe path segment.", 500);
            }
        }

        return $normalizedRoot;
    }
}
