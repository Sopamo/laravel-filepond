<?php

use Nocs\LaravelFilepond\Filepond;

if (! function_exists('filepond')) {
    /**
     * filepond helper
     * @throws \Exception
     */
    function filepond()
    {
        return app(Filepond::class);
    }
}
