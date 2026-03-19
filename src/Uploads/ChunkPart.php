<?php

namespace Sopamo\LaravelFilepond\Uploads;

final class ChunkPart
{
    public function __construct(
        private readonly int $offset,
        private readonly int $size,
        private readonly string $reference
    ) {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Chunk offset must be greater than or equal to zero.');
        }

        if ($size < 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than or equal to zero.');
        }

        if ($reference === '') {
            throw new \InvalidArgumentException('Chunk reference must not be empty.');
        }
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function nextOffset(): int
    {
        return $this->offset + $this->size;
    }
}
