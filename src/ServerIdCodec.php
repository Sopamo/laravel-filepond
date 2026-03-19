<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Str;
use Sopamo\LaravelFilepond\Exceptions\InvalidPathException;
use Sopamo\LaravelFilepond\Uploads\FilepondConfiguration;

class ServerIdCodec
{
    public function __construct(
        private readonly StringEncrypter $encrypter,
        private readonly FilepondConfiguration $configuration
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
        if (!Str::startsWith($filePath, $this->configuration->temporaryFilesPath())) {
            throw new InvalidPathException();
        }

        return $filePath;
    }
}
