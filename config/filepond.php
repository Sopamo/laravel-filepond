<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API middleware
    |--------------------------------------------------------------------------
    |
    | The middleware to append to the filepond API routes
    |
    */
    'middleware' => 'api',

    /*
    |--------------------------------------------------------------------------
    | Prefix
    |--------------------------------------------------------------------------
    |
    | The prefix to add to all filepond controller routes
    |
    */
    'route_prefix' => 'filepond',

    /*
    |--------------------------------------------------------------------------
    | Temporary Path
    |--------------------------------------------------------------------------
    |
    | When initially uploading the files we store them in this path
    | By default, it is stored on the local disk which defaults to `/storage/app/{temporary_files_path}`
    |
    */
    'temporary_files_path' => env('FILEPOND_TEMP_PATH', 'filepond'),
    'temporary_files_disk' => env('FILEPOND_TEMP_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Chunks path
    |--------------------------------------------------------------------------
    |
    | When using chunks, we want to place them inside of this folder.
    | Make sure it is writeable.
    | Chunks use the same disk as the temporary files do.
    |
    */
    'chunks_path' => env('FILEPOND_CHUNKS_PATH', 'filepond' . DIRECTORY_SEPARATOR . 'chunks'),

    'input_name' => 'file',
];
