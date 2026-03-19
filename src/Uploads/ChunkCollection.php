<?php

namespace Sopamo\LaravelFilepond\Uploads;

class ChunkCollection
{
    /**
     * @param array<int, ChunkPart> $parts
     */
    public function __construct(private readonly array $parts)
    {
    }

    /**
     * @return array<int, ChunkPart>
     */
    public function orderedParts(): array
    {
        $orderedParts = array_values($this->parts);

        usort($orderedParts, static function (ChunkPart $leftPart, ChunkPart $rightPart): int {
            return $leftPart->offset() <=> $rightPart->offset();
        });

        return $orderedParts;
    }

    /**
     * @return string[]
     */
    public function orderedReferences(): array
    {
        return array_map(
            static fn (ChunkPart $part): string => $part->reference(),
            $this->orderedParts()
        );
    }

    public function isComplete(int $uploadLength): bool
    {
        if ($uploadLength < 0) {
            return false;
        }

        $nextOffset = 0;

        foreach ($this->orderedParts() as $part) {
            if ($part->size() <= 0) {
                return false;
            }

            if ($part->offset() !== $nextOffset) {
                return false;
            }

            $nextOffset = $part->nextOffset();
        }

        return $nextOffset === $uploadLength;
    }
}
