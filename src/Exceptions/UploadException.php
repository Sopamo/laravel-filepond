<?php

namespace Sopamo\LaravelFilepond\Exceptions;

use RuntimeException;
use Throwable;

class UploadException extends RuntimeException implements LaravelFilepondException
{
    public function __construct(string $message, private readonly int $status = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }
}
