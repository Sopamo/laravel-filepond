<?php

namespace Sopamo\LaravelFilepond\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sopamo\LaravelFilepond\Uploads\AzureChunkManifest;
use Sopamo\LaravelFilepond\Uploads\ChunkPart;

class AzureChunkManifestTest extends TestCase
{
    /** @test */
    public function test_manifest_round_trips_typed_chunk_parts()
    {
        $manifest = AzureChunkManifest::empty()
            ->withUploadLength(11)
            ->withChunk(new ChunkPart(6, 5, 'block-6'))
            ->withChunk(new ChunkPart(0, 6, 'block-0'));

        $decodedManifest = AzureChunkManifest::fromJson($manifest->toJson());

        $this->assertSame(11, $decodedManifest->uploadLength());
        $this->assertSame(['block-0', 'block-6'], $decodedManifest->toChunkCollection()->orderedReferences());
    }

    /** @test */
    public function test_invalid_manifest_payload_is_rejected()
    {
        $this->expectException(\RuntimeException::class);

        AzureChunkManifest::fromJson('{"upload_length":11,"chunks":[{"offset":0,"size":6}]}');
    }
}
