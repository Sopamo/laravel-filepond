<?php

namespace Sopamo\LaravelFilepond\Exceptions;

class InvalidPathException extends \InvalidArgumentException implements LaravelFilepondException
{
    public function __construct(string $message = 'The given file path was invalid', int $code = 400)
    {
        parent::__construct($message, $code);
    }
}
