<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\Tests\TestCase;

class SingleFileUploadTest extends TestCase
{
    public function test_normal_file_upload()
    {
        $tmpPath = config('filepond.temporary_files_path', 'filepond');
        $diskName = config('filepond.temporary_files_disk', 'local');

        Storage::fake($diskName);

        $response = $this->postJson('/filepond/api/process', [
            'file' => UploadedFile::fake()->create('test.txt', 1),
        ]);

        $response->assertStatus(200);
        $serverId = $response->content();
        $this->assertGreaterThan(50, strlen($serverId));

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $pathFromServerId = $filepond->getPathFromServerId($serverId);

        $this->assertStringStartsWith($tmpPath, $pathFromServerId, 'tmp file was not created in the temporary_files_path directory');

        Storage::disk($diskName)->assertExists($pathFromServerId);
    }

    public function test_it_initializes_a_chunked_upload()
    {
        $tmpPath = config('filepond.temporary_files_path', 'filepond');
        $diskName = config('filepond.temporary_files_disk', 'local');

        Storage::fake($diskName);

        $response = $this->postJson('/filepond/api/process', [], [
            'Upload-Name' => 'chunked.txt',
        ]);

        $response->assertStatus(200);

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $pathFromServerId = $filepond->getPathFromServerId($response->content());

        $this->assertStringStartsWith($tmpPath . DIRECTORY_SEPARATOR . 'chunked-', $pathFromServerId);
        $this->assertStringEndsWith('.txt', $pathFromServerId);
        Storage::disk($diskName)->assertExists($pathFromServerId);
    }

    public function test_it_persists_a_chunked_upload_when_all_chunks_have_arrived()
    {
        $diskName = config('filepond.temporary_files_disk', 'local');

        Storage::fake($diskName);

        $initializeResponse = $this->postJson('/filepond/api/process', [], [
            'Upload-Name' => 'chunked.txt',
        ]);

        $initializeResponse->assertStatus(200);
        $serverId = $initializeResponse->content();

        $response = $this->call('PATCH', '/filepond/api', [
            'patch' => $serverId,
        ], [], [], [
            'HTTP_UPLOAD_OFFSET' => '0',
            'HTTP_UPLOAD_LENGTH' => '11',
        ], 'hello world');

        $response->assertStatus(204);

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $pathFromServerId = $filepond->getPathFromServerId($serverId);

        Storage::disk($diskName)->assertExists($pathFromServerId);
        $this->assertSame('hello world', Storage::disk($diskName)->get($pathFromServerId));
        Storage::disk($diskName)->assertMissing(config('filepond.chunks_path') . DIRECTORY_SEPARATOR . basename($pathFromServerId));
    }

    public function test_it_rejects_chunk_uploads_with_invalid_encrypted_ids()
    {
        $response = $this->call('PATCH', '/filepond/api', [
            'patch' => 'not-a-valid-server-id',
        ], [], [], [
            'HTTP_UPLOAD_OFFSET' => '0',
            'HTTP_UPLOAD_LENGTH' => '11',
        ], 'hello world');

        $response->assertStatus(400);
    }

    public function test_it_deletes_an_uploaded_file_folder_from_its_server_id()
    {
        $diskName = config('filepond.temporary_files_disk', 'local');
        $path = config('filepond.temporary_files_path', 'filepond') . DIRECTORY_SEPARATOR . 'delete-me' . DIRECTORY_SEPARATOR . 'test.txt';

        Storage::fake($diskName);
        Storage::disk($diskName)->put($path, 'delete me');

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $serverId = $filepond->getServerIdFromPath($path);

        $response = $this->call('DELETE', '/filepond/api/process', [], [], [], [], $serverId);

        $response->assertStatus(200);
        Storage::disk($diskName)->assertMissing($path);
    }

    public function test_it_rejects_delete_requests_with_invalid_encrypted_ids()
    {
        $response = $this->call('DELETE', '/filepond/api/process', [], [], [], [], 'not-a-valid-server-id');

        $response->assertStatus(400);
    }
}
