<?php

namespace Sopamo\LaravelFilepond\Exceptions;

use InvalidArgumentException;
use Throwable;

class InvalidPathException extends InvalidArgumentException implements LaravelFilepondException
{
    public function __construct(
        string $message = 'The given file path was invalid',
        int $code = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
