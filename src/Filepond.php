<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sopamo\LaravelFilepond\Exceptions\InvalidPathException;

class Filepond
{
    /**
     * Converts the given path into a filepond server id
     *
     * @param  string $path
     *
     * @return string
     */
    public function getServerIdFromPath($path)
    {
        return Crypt::encryptString($path);
    }

    /**
     * Converts the given filepond server id into a path
     *
     * @param  string $serverId
     *
     * @return string
     */
    public function getPathFromServerId($serverId)
    {
        if (! trim($serverId)) {
            throw new InvalidPathException();
        }

        $filePath = Crypt::decryptString($serverId);
        if (! Str::startsWith($filePath, config('filepond.temporary_files_path', 'filepond'))) {
            throw new InvalidPathException();
        }

        return $filePath;
    }
}
