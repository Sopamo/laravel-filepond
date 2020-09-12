<?php

namespace Sopamo\LaravelFilepond\Exceptions;

class InvalidPathException extends \InvalidArgumentException implements LaravelFilepondException
{
    /**
     * @param  string $message
     * @param  int $code
     */
    public function __construct(
        $message = 'The given file path was invalid',
        $code = 400
    ) {
        parent::__construct($message, $code);
    }
}
