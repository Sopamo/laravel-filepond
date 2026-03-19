<?php

namespace Sopamo\LaravelFilepond\Uploads;

class FilepondConfiguration
{
    public function inputName(): string
    {
        return (string) config('filepond.input_name', 'file');
    }

    public function temporaryFilesPath(): string
    {
        return (string) config('filepond.temporary_files_path', 'filepond');
    }

    public function temporaryFilesDisk(): string
    {
        return (string) config('filepond.temporary_files_disk', 'local');
    }

    public function chunksPath(): string
    {
        return (string) config('filepond.chunks_path', 'filepond'.DIRECTORY_SEPARATOR.'chunks');
    }
}
