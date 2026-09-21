<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
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
        $root = rtrim(str_replace('\\', '/', (string) config('filepond.temporary_files_path', 'filepond')), '/');
        $pathToValidate = str_replace('\\', '/', $filePath);
        // Validate the storage key without URL-decoding or otherwise changing it.
        if ($root === '' || !str_starts_with($pathToValidate, $root.'/')
            || preg_match('/[\x00-\x1f\x7f]/', $filePath)
            || array_intersect(explode('/', substr($pathToValidate, strlen($root) + 1)), ['', '.', '..']) !== []) {
            throw new InvalidPathException();
        }

        return $filePath;
    }
}
