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
        $configuredRoot = Config::string('filepond.temporary_files_path');
        $root = str_replace('\\', '/', $configuredRoot);
        $root = rtrim($root, '/');
        $pathToValidate = str_replace('\\', '/', $filePath);

        if ($root === '' || !str_starts_with($pathToValidate, $root.'/')) {
            throw new InvalidPathException();
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $filePath)) {
            throw new InvalidPathException();
        }

        // Check the relative segments without URL-decoding or changing the returned key.
        $relativePath = substr($pathToValidate, strlen($root) + 1);
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidPathException();
            }
        }
    }
}
