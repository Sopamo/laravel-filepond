<?php

namespace Sopamo\LaravelFilepond\Exceptions;

use Throwable;

interface LaravelFilepondException extends Throwable
{
    public function status(): int;
}
