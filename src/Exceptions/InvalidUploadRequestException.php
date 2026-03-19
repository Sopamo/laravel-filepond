<?php

namespace Sopamo\LaravelFilepond\Exceptions;

class InvalidUploadRequestException extends \InvalidArgumentException implements LaravelFilepondException
{
    public function __construct(
        string $message = 'The upload request was invalid',
        int $code = 400,
        ?\Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }
}
