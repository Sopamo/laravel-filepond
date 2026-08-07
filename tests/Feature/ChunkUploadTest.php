<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Sopamo\LaravelFilepond\Filepond;

class ChunkUploadTest extends FilepondFeatureTestCase
{
    public function test_chunk_upload_supports_resume_out_of_order_delivery_and_streamed_assembly(): void
    {
        $serverId = $this->initializeChunkUpload('Safety+guide.pdf', 11);

        $this->patchChunk($serverId, 'world', 6, 11)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '0');

        $this->patchChunk($serverId, 'hello ', 0, 11)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '11');

        $upload = app(Filepond::class)->resolveServerId($serverId);
        $this->assertSame('hello world', $this->storageContents($upload->path));
        $this->storage()->assertMissing('filepond/chunks/'.$upload->id.'/parts');
    }

    public function test_adjacent_chunk_ranges_form_an_exact_partition_at_the_declared_boundary(): void
    {
        $serverId = $this->initializeChunkUpload('adjacent.txt', 8);

        $this->patchChunk($serverId, 'tail', 4, 8)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '0');
        $this->patchChunk($serverId, 'head', 0, 8)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '8');

        $upload = app(Filepond::class)->resolveServerId($serverId);
        $this->assertSame('headtail', $this->storageContents($upload->path));
    }

    public function test_overlapping_chunk_cannot_bypass_the_declared_or_maximum_upload_size(): void
    {
        config()->set('filepond.maximum_upload_size', 8);
        config()->set('filepond.maximum_chunk_size', 8);
        $serverId = $this->initializeChunkUpload('overlap.txt', 8);
        $this->patchChunk($serverId, 'tail', 4, 8)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '0');

        $this->patchChunk($serverId, '12345678', 0, 8)
            ->assertStatus(409)
            ->assertSee('The chunk overlaps an existing chunk.');

        $upload = app(Filepond::class)->resolveServerId($serverId);
        $this->storage()->assertMissing($upload->path);
        $this->storage()->assertMissing('filepond/chunks/'.$upload->id.'/parts/0');
    }

    public function test_one_byte_overlap_is_rejected_but_a_touching_boundary_remains_usable(): void
    {
        $serverId = $this->initializeChunkUpload('one-byte-overlap.txt', 8);
        $this->patchChunk($serverId, 'head', 0, 8)->assertNoContent();

        $this->patchChunk($serverId, 'dtail', 3, 8)
            ->assertStatus(409)
            ->assertSee('The chunk overlaps an existing chunk.');
        $this->patchChunk($serverId, 'tail', 4, 8)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '8');

        $upload = app(Filepond::class)->resolveServerId($serverId);
        $this->assertSame('headtail', $this->storageContents($upload->path));
    }

    public function test_persisted_overlapping_ranges_are_rejected_before_assembly(): void
    {
        $serverId = $this->initializeChunkUpload('invalid-manifest.txt', 8);
        $upload = app(Filepond::class)->resolveServerId($serverId);
        $chunkRoot = 'filepond/chunks/'.$upload->id;
        $this->storage()->put($chunkRoot.'/parts/0', '12345678');
        $this->storage()->put($chunkRoot.'/parts/4', 'tail');
        $this->storage()->put($chunkRoot.'/manifest.json', json_encode([
            'version' => 1,
            'path' => $upload->path,
            'length' => 8,
            'parts' => [
                '0' => ['size' => 8, 'checksum' => hash('sha256', '12345678')],
                '4' => ['size' => 4, 'checksum' => hash('sha256', 'tail')],
            ],
            'completed' => false,
        ], JSON_THROW_ON_ERROR));

        $this->patchChunk($serverId, '12345678', 0, 8)
            ->assertStatus(500)
            ->assertSee('The chunk upload state contains invalid or overlapping ranges.');
        $this->storage()->assertMissing($upload->path);
    }

    #[DataProvider('corruptedStoredPartProvider')]
    public function test_assembly_rejects_stored_part_bytes_that_do_not_match_the_manifest(
        string $storedPartContents,
    ): void {
        $serverId = $this->initializeChunkUpload('corrupt-part.txt', 8);
        $this->patchChunk($serverId, 'head', 0, 8)->assertNoContent();
        $upload = app(Filepond::class)->resolveServerId($serverId);
        $chunkRoot = 'filepond/chunks/'.$upload->id;
        $this->storage()->put($chunkRoot.'/parts/0', $storedPartContents);

        $this->patchChunk($serverId, 'tail', 4, 8)
            ->assertStatus(500)
            ->assertSee('The uploaded chunks failed integrity validation.');

        $this->storage()->assertMissing($upload->path);
        $manifest = json_decode(
            $this->storageContents($chunkRoot.'/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertArrayNotHasKey('4', $manifest['parts']);
    }

    /** @return array<string, array{string}> */
    public static function corruptedStoredPartProvider(): array
    {
        return [
            'truncated bytes' => ['hea'],
            'changed same-size bytes' => ['HEAD'],
            'oversized bytes' => ['head!'],
        ];
    }

    public function test_filepond_can_use_the_server_id_as_a_literal_patch_query_value(): void
    {
        $serverId = $this->initializeChunkUpload('query-safe.txt', 4);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $serverId);
        $this->call('PATCH', '/filepond/api?patch='.$serverId, [], [], [], [
            'HTTP_UPLOAD_OFFSET' => '0',
            'HTTP_UPLOAD_LENGTH' => '4',
        ], 'data')->assertNoContent()->assertHeader('Upload-Offset', '4');
    }

    public function test_chunk_initialization_accepts_filepond_metadata_in_the_file_field(): void
    {
        $response = $this->post('/filepond/api/process', [
            'file' => json_encode(['relativePath' => 'manuals/Safety+guide.pdf'], JSON_THROW_ON_ERROR),
        ], [
            'Upload-Name' => 'Safety+guide.pdf',
            'Upload-Length' => '11',
        ]);

        $response->assertOk();
        $upload = app(Filepond::class)->resolveServerId($response->content());

        $this->assertSame('Safety+guide.pdf', $upload->originalName);
        $this->storage()->assertExists('filepond/chunks/'.$upload->id.'/manifest.json');
    }

    public function test_identical_chunk_retry_is_idempotent_and_conflicting_retry_is_rejected(): void
    {
        $serverId = $this->initializeChunkUpload('retry.txt', 8);

        $this->patchChunk($serverId, 'part', 0, 8)->assertNoContent();
        $this->patchChunk($serverId, 'part', 0, 8)->assertNoContent()->assertHeader('Upload-Offset', '4');
        $this->patchChunk($serverId, 'else', 0, 8)->assertStatus(409);
    }

    public function test_identical_last_chunk_retry_recovers_an_interrupted_manifest_commit(): void
    {
        $serverId = $this->initializeChunkUpload('recovery.txt', 8);
        $this->patchChunk($serverId, 'part', 0, 8)->assertNoContent();
        $upload = app(Filepond::class)->resolveServerId($serverId);
        $chunkRoot = 'filepond/chunks/'.$upload->id;
        $this->storage()->put($chunkRoot.'/parts/4', 'tail');
        $this->storage()->put($upload->path, 'stale final data');
        $this->storage()->put($chunkRoot.'/manifest.json', json_encode([
            'version' => 1,
            'path' => $upload->path,
            'length' => 8,
            'parts' => [
                '0' => ['size' => 4, 'checksum' => hash('sha256', 'part')],
                '4' => ['size' => 4, 'checksum' => hash('sha256', 'tail')],
            ],
            'completed' => false,
        ], JSON_THROW_ON_ERROR));

        $this->patchChunk($serverId, 'tail', 4, 8)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '8');

        $this->assertSame('parttail', $this->storageContents($upload->path));
        $manifest = json_decode($this->storageContents($chunkRoot.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($manifest['completed']);
        $this->storage()->assertMissing($chunkRoot.'/parts');
    }

    public function test_gap_does_not_finalize_the_upload(): void
    {
        $serverId = $this->initializeChunkUpload('gap.txt', 8);
        $this->patchChunk($serverId, 'tail', 4, 8)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '0');

        $upload = app(Filepond::class)->resolveServerId($serverId);
        $this->storage()->assertMissing($upload->path);
    }

    public function test_chunk_length_change_and_excessive_body_are_rejected(): void
    {
        $serverId = $this->initializeChunkUpload('invalid.txt', 8);

        $this->patchChunk($serverId, 'part', 0, 9)->assertStatus(409);
        $this->patchChunk($serverId, 'too-long', 4, 8)->assertStatus(422);
    }

    public function test_zero_byte_chunk_upload_is_completed_during_initialization(): void
    {
        $serverId = $this->initializeChunkUpload('empty.txt', 0);
        $upload = app(Filepond::class)->resolveServerId($serverId);

        $this->storage()->assertExists($upload->path);
        $this->assertSame('', $this->storageContents($upload->path));
    }

    public function test_chunk_upload_accepts_the_previously_reserved_metadata_filename(): void
    {
        $serverId = $this->initializeChunkUpload('.filepond-upload.json', 4);

        $this->patchChunk($serverId, 'data', 0, 4)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '4');

        $upload = app(Filepond::class)->resolveServerId($serverId);
        $this->assertSame('.filepond-upload.json', basename($upload->path));
        $this->assertSame('data', $this->storageContents($upload->path));
        $this->storage()->assertMissing('filepond/chunks/'.$upload->id.'/parts');
    }

    public function test_invalid_patch_headers_return_precise_validation_keys(): void
    {
        $serverId = $this->initializeChunkUpload('invalid.txt', 8);

        $response = $this->call('PATCH', '/filepond/api', ['patch' => $serverId], [], [], [
            'HTTP_UPLOAD_OFFSET' => 'invalid',
            'HTTP_UPLOAD_LENGTH' => '8',
            'HTTP_ACCEPT' => 'application/json',
        ], 'part');

        $response->assertStatus(400)->assertJsonValidationErrors('_upload_offset');
    }

    public function test_tampered_server_id_is_rejected(): void
    {
        $this->call('PATCH', '/filepond/api', ['patch' => 'tampered'], [], [], [
            'HTTP_UPLOAD_OFFSET' => '0',
            'HTTP_UPLOAD_LENGTH' => '4',
        ], 'data')
            ->assertStatus(400);
    }

    public function test_chunk_upload_accepts_a_configured_lock_lease(): void
    {
        config()->set('filepond.lock_seconds', 30);
        $serverId = $this->initializeChunkUpload('manual.pdf', 4);

        $this->patchChunk($serverId, 'data', 0, 4)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '4');
    }

    public function test_chunk_upload_rejects_a_non_positive_lock_lease(): void
    {
        config()->set('filepond.lock_seconds', 0);
        $serverId = $this->initializeChunkUpload('manual.pdf', 4);

        $this->patchChunk($serverId, 'data', 0, 4)
            ->assertStatus(500)
            ->assertSee('FILEPOND_LOCK_SECONDS must be greater than zero.');
    }
}
