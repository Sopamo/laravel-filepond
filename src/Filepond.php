<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Sopamo\LaravelFilepond\Exceptions\InvalidPathException;

class Filepond {
    /**
     * Converts the given path into a filepond server id
     *
     * @param string $path
     * @return string
     */
    public function getServerIdFromPath($path) {
        return Crypt::encryptString($path);
    }

    /**
     * Converts the given filepond server id into a path
     *
     * @param string $serverId
     * @return string
     */
    public function getPathFromServerId($serverId) {
        if(!trim($serverId)) {
            throw new InvalidPathException();
        }
        $filePath = Crypt::decryptString($serverId);
        if(!Str::startsWith($filePath, config('filepond.temporary_files_path'))) {
            throw new InvalidPathException();
        }
        return $filePath;
    }

    /**
     * Save the original filename with extension
     *
     * @param UploadedFile $uploadedFile
     * @param string $serverId
     * @return void
     */
    public function saveOriginalFilename($uploadedFile, $serverId)
    {
        \Cache::put($serverId . '_orig_name', $uploadedFile->getClientOriginalName(), 60);
    }

    /**
     * Get the original filename with extension
     *
     * @param string $serverId
     * @return string
     */
    public function getOriginalFileNameFromServerId($serverId)
    {
        return \Cache::get($serverId . '_orig_name');
    }

    /**
     * Delete the original filename with extension
     *
     * @param string $serverId
     * @return void
     */
    public function deleteOriginalFileNameByServerId($serverId)
    {
        \Cache::remove($serverId . '_orig_name');
    }
}