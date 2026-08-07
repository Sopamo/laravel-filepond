<?php

namespace Sopamo\LaravelFilepond;

final readonly class TemporaryUpload
{
    public function __construct(
        public string $id,
        public string $disk,
        public string $path,
        public string $originalName,
        public bool $legacy = false,
    ) {
    }
}
