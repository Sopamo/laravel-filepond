<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Package routes
    |--------------------------------------------------------------------------
    |
    | Routes remain enabled by default for compatibility with version 2. Set
    | this to false when the host application registers the controller itself.
    |
    */
    'routes_enabled' => env('FILEPOND_ROUTES_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | API middleware
    |--------------------------------------------------------------------------
    |
    | The middleware to append to the filepond API routes. Add authentication,
    | authorization, and rate limiting before enabling the routes.
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
    'chunks_path' => env('FILEPOND_CHUNKS_PATH', 'filepond/chunks'),

    'input_name' => 'file',

    'maximum_upload_size' => env('FILEPOND_MAXIMUM_UPLOAD_SIZE', PHP_INT_MAX),
    'maximum_chunk_size' => env('FILEPOND_MAXIMUM_CHUNK_SIZE', PHP_INT_MAX),
    'lock_store' => env('FILEPOND_LOCK_STORE'),
    'lock_seconds' => env('FILEPOND_LOCK_SECONDS', 900),
    'lock_wait_seconds' => env('FILEPOND_LOCK_WAIT_SECONDS', 5),
];
