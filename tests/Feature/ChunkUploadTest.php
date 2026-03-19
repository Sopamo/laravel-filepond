<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\Tests\TestCase;

class ChunkUploadTest extends TestCase
{
    /** @test */
    public function test_chunk_upload_is_assembled_on_non_azure_storage()
    {
        $diskName = config('filepond.temporary_files_disk', 'local');
        $temporaryFilesPath = config('filepond.temporary_files_path', 'filepond');

        Storage::fake($diskName);

        $serverId = $this->initializeChunkUpload('archive.wbt', 11);

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $finalFilePath = $filepond->getPathFromServerId($serverId);
        $chunkStoragePath = $this->buildChunkStoragePath($finalFilePath);

        $this->assertStringStartsWith($temporaryFilesPath.DIRECTORY_SEPARATOR, $finalFilePath);
        $this->assertNotSame($temporaryFilesPath, dirname($finalFilePath));

        $this->sendChunk($serverId, 'hello ', 0, 11)->assertStatus(204);
        Storage::disk($diskName)->assertExists($chunkStoragePath.DIRECTORY_SEPARATOR.'patch.0');

        $this->sendChunk($serverId, 'world', 6, 11)->assertStatus(204);

        Storage::disk($diskName)->assertExists($finalFilePath);
        $this->assertSame('hello world', Storage::disk($diskName)->get($finalFilePath));
        Storage::disk($diskName)->assertMissing($chunkStoragePath.DIRECTORY_SEPARATOR.'patch.0');
        Storage::disk($diskName)->assertMissing($chunkStoragePath.DIRECTORY_SEPARATOR.'patch.6');
    }

    /** @test */
    public function test_deleting_a_chunk_upload_only_deletes_its_upload_directory()
    {
        $diskName = config('filepond.temporary_files_disk', 'local');
        $temporaryFilesPath = config('filepond.temporary_files_path', 'filepond');

        Storage::fake($diskName);

        $serverId = $this->initializeChunkUpload('archive.wbt', 4);

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $finalFilePath = $filepond->getPathFromServerId($serverId);
        $chunkStoragePath = $this->buildChunkStoragePath($finalFilePath);
        $unrelatedFilePath = $temporaryFilesPath.DIRECTORY_SEPARATOR.'another-upload'.DIRECTORY_SEPARATOR.'keep.txt';

        Storage::disk($diskName)->put($unrelatedFilePath, 'keep me');

        $this->sendChunk($serverId, 'test', 0, 4)->assertStatus(204);

        $deleteResponse = $this->call(
            'DELETE',
            '/filepond/api/process',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $serverId
        );

        $deleteResponse->assertStatus(200);

        Storage::disk($diskName)->assertMissing($finalFilePath);
        Storage::disk($diskName)->assertMissing($chunkStoragePath.DIRECTORY_SEPARATOR.'patch.0');
        Storage::disk($diskName)->assertExists($unrelatedFilePath);
    }

    private function initializeChunkUpload(string $uploadName, int $uploadLength): string
    {
        $response = $this->call(
            'POST',
            '/filepond/api/process',
            [],
            [],
            [],
            [
                'HTTP_UPLOAD_LENGTH' => (string) $uploadLength,
                'HTTP_UPLOAD_NAME' => $uploadName,
            ]
        );

        $response->assertStatus(200);

        return $response->content();
    }

    private function sendChunk(string $serverId, string $chunkContent, int $offset, int $uploadLength)
    {
        return $this->call(
            'PATCH',
            '/filepond/api',
            ['patch' => $serverId],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/offset+octet-stream',
                'HTTP_UPLOAD_OFFSET' => (string) $offset,
                'HTTP_UPLOAD_LENGTH' => (string) $uploadLength,
            ],
            $chunkContent
        );
    }

    private function buildChunkStoragePath(string $finalFilePath): string
    {
        return config('filepond.chunks_path').DIRECTORY_SEPARATOR.sha1($finalFilePath);
    }
}
