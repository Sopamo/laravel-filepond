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
    | Local Temporary Path
    |--------------------------------------------------------------------------
    |
    | When initially uploading the files we store them in this path
    |
    */
    'temporary_files_path' => realpath(sys_get_temp_dir()),
    'input_name' => 'file',
];
