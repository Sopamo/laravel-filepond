<?php

namespace Sopamo\LaravelFilepond\Uploads;

final class AzureChunkManifest
{
    /**
     * @param array<int, ChunkPart> $partsByOffset
     */
    private function __construct(
        private int $uploadLength,
        private array $partsByOffset
    ) {
    }

    public static function empty(): self
    {
        return new self(0, []);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid Azure block blob chunk upload manifest.');
        }

        $uploadLength = self::integerValue($decoded['upload_length'] ?? null);
        $chunks = $decoded['chunks'] ?? null;

        if (!is_array($chunks)) {
            throw new \RuntimeException('Invalid Azure block blob chunk upload manifest.');
        }

        $manifest = new self($uploadLength, []);

        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                throw new \RuntimeException('Invalid Azure block blob chunk upload manifest.');
            }

            $offset = self::integerValue($chunk['offset'] ?? null);
            $size = self::integerValue($chunk['size'] ?? null);
            $blockId = $chunk['block_id'] ?? null;

            if (!is_string($blockId)) {
                throw new \RuntimeException('Invalid Azure block blob chunk upload manifest.');
            }

            $manifest = $manifest->withChunk(new ChunkPart($offset, $size, $blockId));
        }

        return $manifest;
    }

    public function withUploadLength(int $uploadLength): self
    {
        if ($uploadLength < 0) {
            throw new \InvalidArgumentException('Upload length must be greater than or equal to zero.');
        }

        $clone = clone $this;
        $clone->uploadLength = $uploadLength;

        return $clone;
    }

    public function withChunk(ChunkPart $part): self
    {
        $clone = clone $this;
        $clone->partsByOffset[$part->offset()] = $part;

        return $clone;
    }

    public function uploadLength(): int
    {
        return $this->uploadLength;
    }

    /**
     * @return array<int, ChunkPart>
     */
    public function parts(): array
    {
        return array_values($this->partsByOffset);
    }

    public function toChunkCollection(): ChunkCollection
    {
        return new ChunkCollection($this->parts());
    }

    public function toJson(): string
    {
        $payload = [
            'upload_length' => $this->uploadLength,
            'chunks' => array_map(
                static fn (ChunkPart $part): array => [
                    'offset' => $part->offset(),
                    'size' => $part->size(),
                    'block_id' => $part->reference(),
                ],
                $this->parts()
            ),
        ];

        $json = json_encode($payload);
        if (!is_string($json)) {
            throw new \RuntimeException('Could not encode the Azure block blob chunk upload manifest.');
        }

        return $json;
    }

    private static function integerValue(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \RuntimeException('Invalid Azure block blob chunk upload manifest.');
    }
}
