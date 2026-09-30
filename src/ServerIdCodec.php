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

        if ($root === '') {
            // Disk-root uploads may have leading separators in historical IDs.
            $relativePath = ltrim($pathToValidate, '/');
        } else {
            // Require the directory prefix and separator so sibling roots do not match.
            if (!str_starts_with($pathToValidate, $root.'/')) {
                throw new InvalidPathException();
            }

            $relativePath = substr($pathToValidate, strlen($root) + 1);
        }

        // Reject control characters, including null bytes and line breaks.
        if (preg_match('/[\x00-\x1f\x7f]/', $filePath)) {
            throw new InvalidPathException();
        }

        // Reject traversal and segments that storage backends may normalize
        // differently, such as "upload/../file.pdf" or "upload//file.pdf".
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidPathException();
            }
        }
    }
}
