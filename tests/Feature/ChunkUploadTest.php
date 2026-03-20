<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\Tests\TestCase;

class ChunkUploadTest extends TestCase
{
    /** @test */
    public function test_generic_chunk_upload_is_assembled_after_all_chunks()
    {
        $diskName = 'local';
        $content = 'Hello world';

        config()->set('filepond.temporary_files_disk', $diskName);
        Storage::fake($diskName);

        $response = $this->initChunkUpload('generic.txt');
        $response->assertStatus(200);

        $serverId = $response->getContent();
        $filePath = app(Filepond::class)->getPathFromServerId($serverId);
        $uploadId = basename($filePath);
        $chunkBasePath = config('filepond.chunks_path') . DIRECTORY_SEPARATOR . $uploadId;

        $this->patchChunk($serverId, 'Hello ', 0, strlen($content))->assertStatus(204);
        $this->patchChunk($serverId, 'world', 6, strlen($content))->assertStatus(204);

        $storage = Storage::disk($diskName);

        $this->assertSame($content, $storage->get($filePath));
        $this->assertFalse($storage->exists($chunkBasePath));
        $this->assertFalse($storage->exists($chunkBasePath . DIRECTORY_SEPARATOR . 'upload.json'));
    }

    /** @test */
    public function test_chunk_delete_only_removes_the_target_chunk_upload()
    {
        $diskName = 'local';

        config()->set('filepond.temporary_files_disk', $diskName);
        Storage::fake($diskName);

        $storage = Storage::disk($diskName);
        $unrelatedPath = config('filepond.temporary_files_path', 'filepond') . DIRECTORY_SEPARATOR . 'keep.txt';
        $storage->put($unrelatedPath, 'keep');

        $response = $this->initChunkUpload('delete.txt');
        $response->assertStatus(200);

        $serverId = $response->getContent();
        $filePath = app(Filepond::class)->getPathFromServerId($serverId);
        $uploadId = basename($filePath);
        $chunkBasePath = config('filepond.chunks_path') . DIRECTORY_SEPARATOR . $uploadId;

        $this->patchChunk($serverId, 'part', 0, 8)->assertStatus(204);

        $this->deleteUpload($serverId)->assertStatus(200);

        $this->assertFalse($storage->exists($filePath));
        $this->assertFalse($storage->exists($chunkBasePath));
        $this->assertTrue($storage->exists($unrelatedPath));
    }

    /** @test */
    public function test_azure_oss_chunk_upload_uses_native_block_blob_staging()
    {
        $diskName = 'azure_oss';
        $content = 'Hello Azure';
        $baseRoot = $this->makeDiskRoot('azure-oss');

        config()->set('filesystems.disks.' . $diskName, [
            'driver' => 'fake-azure-oss',
            'test_root' => $baseRoot,
            'container' => 'test-container',
            'prefix' => 'tenant-a',
        ]);
        config()->set('filepond.temporary_files_disk', $diskName);

        $response = $this->initChunkUpload('azure-oss.txt');
        $response->assertStatus(200);

        $serverId = $response->getContent();
        $filePath = app(Filepond::class)->getPathFromServerId($serverId);
        $uploadId = basename($filePath);
        $chunkBasePath = config('filepond.chunks_path') . DIRECTORY_SEPARATOR . $uploadId;
        $blockMarkerPath = $chunkBasePath . DIRECTORY_SEPARATOR . 'blocks' . DIRECTORY_SEPARATOR . '0.json';
        $patchPath = $chunkBasePath . DIRECTORY_SEPARATOR . 'patch.0';

        $this->patchChunk($serverId, 'Hello ', 0, strlen($content))->assertStatus(204);

        $storage = Storage::disk($diskName);
        $this->assertTrue($storage->exists($blockMarkerPath));
        $this->assertFalse($storage->exists($patchPath));

        $this->patchChunk($serverId, 'Hello ', 0, strlen($content))->assertStatus(204);
        $this->patchChunk($serverId, 'Azure', 6, strlen($content))->assertStatus(204);

        $this->assertSame($content, $storage->get($filePath));
        $this->assertFalse($storage->exists($chunkBasePath));
    }

    /** @test */
    public function test_legacy_azure_chunk_upload_keeps_prefix_aware_append_blob_support()
    {
        $diskName = 'legacy_azure';
        $content = 'Legacy Azure';
        $baseRoot = $this->makeDiskRoot('legacy-azure');

        config()->set('filesystems.disks.' . $diskName, [
            'driver' => 'fake-legacy-azure',
            'test_root' => $baseRoot,
            'container' => 'test-container',
            'prefix' => 'legacy-prefix',
        ]);
        config()->set('filepond.temporary_files_disk', $diskName);

        $response = $this->initChunkUpload('legacy.txt');
        $response->assertStatus(200);

        $serverId = $response->getContent();
        $filePath = app(Filepond::class)->getPathFromServerId($serverId);

        $this->patchChunk($serverId, 'Legacy ', 0, strlen($content))->assertStatus(204);
        $this->patchChunk($serverId, 'Azure', 7, strlen($content))->assertStatus(204);

        $this->assertSame($content, Storage::disk($diskName)->get($filePath));
    }
}
