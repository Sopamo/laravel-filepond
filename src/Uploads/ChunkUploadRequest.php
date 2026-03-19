<?php

namespace Sopamo\LaravelFilepond\Uploads;

final class ChunkUploadRequest
{
    public function __construct(
        private readonly string $finalFilePath,
        private readonly int $offset,
        private readonly int $length
    )
    {
        if ($finalFilePath === '') {
            throw new \InvalidArgumentException('Final file path must not be empty.');
        }

        if ($offset < 0 || $length < 0) {
            throw new \InvalidArgumentException('Chunk length and offset must be greater than or equal to zero.');
        }
    }

    public function finalFilePath(): string
    {
        return $this->finalFilePath;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function length(): int
    {
        return $this->length;
    }
}
