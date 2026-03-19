<?php

namespace Sopamo\LaravelFilepond\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sopamo\LaravelFilepond\Uploads\ChunkCollection;
use Sopamo\LaravelFilepond\Uploads\ChunkPart;

class ChunkCollectionTest extends TestCase
{
    /** @test */
    public function test_out_of_order_contiguous_chunks_are_complete()
    {
        $chunkCollection = new ChunkCollection([
            new ChunkPart(6, 5, 'patch.6'),
            new ChunkPart(0, 6, 'patch.0'),
        ]);

        $this->assertTrue($chunkCollection->isComplete(11));
        $this->assertSame(['patch.0', 'patch.6'], $chunkCollection->orderedReferences());
    }

    /** @test */
    public function test_chunks_with_a_gap_are_not_complete()
    {
        $chunkCollection = new ChunkCollection([
            new ChunkPart(0, 5, 'patch.0'),
            new ChunkPart(6, 5, 'patch.6'),
        ]);

        $this->assertFalse($chunkCollection->isComplete(11));
    }

    /** @test */
    public function test_chunks_with_an_overlap_are_not_complete()
    {
        $chunkCollection = new ChunkCollection([
            new ChunkPart(0, 6, 'patch.0'),
            new ChunkPart(5, 5, 'patch.5'),
        ]);

        $this->assertFalse($chunkCollection->isComplete(11));
    }

    /** @test */
    public function test_zero_sized_chunks_are_not_complete()
    {
        $chunkCollection = new ChunkCollection([
            new ChunkPart(0, 0, 'patch.0'),
        ]);

        $this->assertFalse($chunkCollection->isComplete(0));
    }

    /** @test */
    public function test_negative_chunk_offset_is_rejected()
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChunkPart(-1, 1, 'patch.0');
    }
}
