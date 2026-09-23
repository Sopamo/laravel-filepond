<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Facades\Config;
use Sopamo\LaravelFilepond\Exceptions\InvalidPathException;

class ServerIdCodec
{
    public function __construct(
        private readonly StringEncrypter $encrypter
    ) {
    }

    public function encode(string $path): string
    {
        return $this->encrypter->encryptString($path);
    }

    /**
     * @throws DecryptException
     * @throws InvalidPathException
     */
    public function decode(string $serverId): string
    {
        if (trim($serverId) === '') {
            throw new InvalidPathException();
        }

        $filePath = $this->encrypter->decryptString($serverId);
        $this->validatePath($filePath);

        return $filePath;
    }

    private function validatePath(string $filePath): void
    {
        // Normalize separators for validation without changing the original path.
        $root = str_replace('\\', '/', Config::string('filepond.temporary_files_path'));
        $root = rtrim($root, '/');
        $pathToValidate = str_replace('\\', '/', $filePath);

        // Require the temporary directory prefix, including its separator.
        // For example, "filepond-other/file.pdf" must not match "filepond".
        if ($root === '' || !str_starts_with($pathToValidate, $root.'/')) {
            throw new InvalidPathException();
        }

        // Reject control characters, including null bytes and line breaks.
        if (preg_match('/[\x00-\x1f\x7f]/', $filePath)) {
            throw new InvalidPathException();
        }

        // Inspect only the path beneath the configured temporary directory.
        $relativePath = substr($pathToValidate, strlen($root) + 1);

        // Reject traversal and segments that storage backends may normalize
        // differently, such as "upload/../file.pdf" or "upload//file.pdf".
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidPathException();
            }
        }
    }
}
