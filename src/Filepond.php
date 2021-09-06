<?php

namespace Nocs\LaravelFilepond;

use Exception;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nocs\LaravelFilepond\Exceptions\InvalidPathException;

class Filepond
{
    /**
     * Converts the given path into a filepond server id
     *
     * @param  string $path
     *
     * @return string
     */
    /* @todo: when using sessions with uuid's
    public function getServerIdFromPath($path)
    {

        $serverId = $this->pathToToken($path);

        session(['uploads.'.$serverId => $path]);

        return $serverId;
    }*/
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
    /* @todo: when using sessions with uuid's
    public function getPathFromServerId($serverId)
    {
        if (! trim($serverId)) {
            throw new InvalidPathException();
        }

        $filePath = session('uploads.'.$serverId);

        if (! Str::startsWith($filePath, $this->getBasePath())) {
            throw new InvalidPathException();
        }

        return $filePath;
    }*/
    public function getPathFromServerId($serverId)
    {
        if (! trim($serverId)) {
            throw new InvalidPathException();
        }

        $filePath = Crypt::decryptString($serverId);
        if (! Str::startsWith($filePath, $this->getBasePath())) {
            throw new InvalidPathException();
        }

        return $filePath;
    }

    /**
     * Get the storage base path for files.
     *
     * @return string
     */
    public function getBasePath()
    {
        return Storage::disk(config('filepond.temporary_files_disk', 'local'))
            ->path(config('filepond.temporary_files_path', 'filepond'));
    }


    public function getRelativePathFromServerId($serverId)
    {
        $filepond = app(Filepond::class);
        $tmpPath = $filepond->getPathFromServerId($serverId);
        return preg_replace('/^' . preg_quote(storage_path('app') . '/', '/') . '/', '', $tmpPath);
    }

    public function pathToToken(string $path): string
    {
        return preg_replace(
            '/^([0-9a-f]{12,12})([0-9a-f]{4,4})([0-9a-f]{4,4})([0-9a-f]{4,4})([0-9a-f]{8,8})$/',
            '${1}-${2}-${3}-${4}-${5}', md5($this->forceRelative($path)));
    }

    public function forceRelative(string $path): string
    {
        return preg_replace('/^'.preg_quote(storage_path('app'), '/').'\//', '', $path);
    }

    public function getStoreDataFromPath($path)
    {

        if (empty($path)) {
            return $path;
        }

        $relativePath = $this->forceRelative($path);

        if (!Storage::disk('local')->exists($relativePath)) {
            return null;
        }

        $basename = basename($path);

        $mimeType = Storage::disk('local')->mimeType($relativePath);

        $token = $this->pathToToken($path);

        $filesize = Storage::disk('local')->size($relativePath);

        $fileData = (object) [
            'token'    => $token,
            'filename' => $basename,
            'path'     => $relativePath,
            'size'     => $filesize,
            'mimetype' => $mimeType,
        ];

        if (preg_match('/^image\//', $mimeType)) {
            $dimensions = getimagesize(Storage::disk('local')->path($relativePath));
            $fileData->width   = isset($dimensions[0]) ? $dimensions[0] : null;
            $fileData->height  = isset($dimensions[1]) ? $dimensions[1] : null;
            $fileData->focus_x = 0.5;
            $fileData->focus_y = 0.5;
        }

        return $fileData;
    }

    public function publishUpload($tmpPath, $dirName = 'uploads')
    {

        $basename = basename($tmpPath);

        $newPath = 'public/' . $dirName . '/' . substr(md5(session()->getId()), 0, 12) . '/' . substr(md5($tmpPath), 0, 012) . '/' . $basename;

        if (!Storage::move($tmpPath, $newPath)) {
            return false;
        }

        return $this->getStoreDataFromPath($newPath);
    }

    public function getFileDataFromStoredByToken(array $stored, string $token)
    {
        foreach ($stored as $fileData) {
            if (($fileData['token'] ?? null) == $token) {
                return $fileData;
            }
        }
        return null;
    }

    public function unmask(array $postData, $storedData): array
    {

        $unmasked = [];

        if (!is_array($storedData)) {
            try {
                $storedData = json_decode($storedData);
            } catch (Exception $e) {
                $storedData = [];
            }
        }

        foreach (['c', 'r', 'd'] as $action) {
            $unmasked[$action] = [];
            foreach ($postData[$action] ?? [] as $serverId) {
                if (!empty($serverId)) {
                    if ($action == 'c') {
                        $path = $this->getPathFromServerId($serverId);
                        if ($path && ($storeData = $this->getStoreDataFromPath($path))) {
                            $unmasked[$action][$storeData->token] = $storeData;
                        }
                    } elseif ($fileData = $this->getFileDataFromStoredByToken($storedData, $serverId)) {
                        $unmasked[$action][$serverId] = $fileData;
                    }
                }
            }
        }

        return $unmasked;
    }

    public function uniquePath(string $path): string
    {
        while (Storage::exists($path)) {
            $path = preg_match('/^(.+)\.([1-9]+[0-9]*)\.([^\.\/]+)$/', $path, $m)
                ? $m[1].'.'.((int) $m[2] + 1).'.'.$m[3]
                : (preg_match('/^(.+)\.([^\.\/]+)$/', $path, $m) ? $m[1].'.1.'.$m[2] : $path.'.1');
        }

        return $path;
    }

    public function process(array $data, array $options = []): array
    {

        $options = array_replace([

            // target path to store new files
            'path' => 'uploads',

            // remove deleted files physically
            'delete' => false,

        ], $options ?? []);

        $files = [];

        // deletes
        foreach ($data['d'] ?? [] as $serverId => $fileData) {

            if (isset($data['r'][$serverId])) {
                unset($data['r'][$serverId]);
            }

            if (is_array($fileData)) {
                $fileData = json_decode(json_encode($fileData));
            }
            if (is_object($fileData)) {

                // unlink
                if ($options['delete'] && Storage::exists($fileData->path)) {
                    Storage::delete($fileData->path);
                }
            }
        }

        // old ones
        foreach ($data['r'] ?? [] as $serverId => $fileData) {
            if (is_array($fileData)) {
                $fileData = json_decode(json_encode($fileData));
            }
            if (is_object($fileData)) {
                $files[] = $fileData;
            }
        }

        // creates
        foreach ($data['c'] ?? [] as $serverId => $fileData) {
            if (is_array($fileData)) {
                $fileData = json_decode(json_encode($fileData));
            }
            if (is_object($fileData)) {
                $newPath = ((!empty($options['path']) && ($options['path'] != '/')) ? rtrim($options['path'], '/').'/' : '')
                    .$fileData->filename;
                $newPath = $this->uniquePath($newPath);
                if ($fileData->path != $newPath) {
                    $token = $this->pathToToken($newPath);
                    if (!Storage::move($fileData->path, $newPath)) {
                        continue;
                    }
                    $fileData->path = $newPath;
                    $fileData->token = $token;
                }
                $files[] = $fileData;
            }
        }

        return $files;
    }

    public function processFromPost($posted, $current, $path = 'uploads'): array
    {

        if (is_string($posted)) {
            try {
                $posted = json_decode($posted, true);
            } catch (\Exception $e) {
                $posted = [];
            }
        } elseif (is_object($posted)) {
            $posted = json_decode(json_encode($posted), true);
        }
        $current = $current ?? [];

        $posted = $this->unmask($posted, $current);
        $posted = $this->process($posted, ['path' => $path]);

        return $posted;
    }

}
