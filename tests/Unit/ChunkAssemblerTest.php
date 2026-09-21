<?php

namespace Sopamo\LaravelFilepond\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sopamo\LaravelFilepond\ChunkAssembler;
use Sopamo\LaravelFilepond\Exceptions\UploadException;
use Sopamo\LaravelFilepond\TemporaryUpload;
use Sopamo\LaravelFilepond\Tests\TestCase;

class ChunkAssemblerTest extends TestCase
{
    #[DataProvider('invalidChunkProvider')]
    public function test_invalid_chunks_are_rejected_before_storage(
        int $offset,
        int $length,
        string $content,
        string $expectedMessage,
    ): void {
        config()->set('filepond.maximum_chunk_size', 4);
        $upload = new TemporaryUpload(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'local',
            'filepond/01ARZ3NDEKTSV4RRFFQ69G5FAV/file.txt',
            'file.txt',
        );

        try {
            app(ChunkAssembler::class)->store($upload, $offset, $length, $content);
            $this->fail('The invalid chunk was accepted.');
        } catch (UploadException $exception) {
            $this->assertSame(422, $exception->status());
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    /** @return array<string, array{int, int, string, string}> */
    public static function invalidChunkProvider(): array
    {
        return [
            'negative offset' => [-1, 4, 'a', 'The chunk offset cannot be negative.'],
            'empty chunk' => [0, 4, '', 'The chunk cannot be empty.'],
            'chunk above configured maximum' => [0, 5, '12345', 'The chunk exceeds the configured maximum chunk size.'],
            'offset beyond declared length' => [5, 4, 'a', 'The chunk offset exceeds the declared upload length.'],
            'chunk beyond declared length' => [3, 4, 'ab', 'The chunk extends beyond the declared upload length.'],
        ];
    }
}
