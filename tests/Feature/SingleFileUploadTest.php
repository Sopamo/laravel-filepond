<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\Tests\TestCase;

class SingleFileUploadTest extends TestCase
{
    /** @test */
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

    /** @test */
    public function test_delete_succeeds_for_a_normal_file_upload_without_a_chunk_directory()
    {
        $diskName = config('filepond.temporary_files_disk', 'local');

        Storage::fake($diskName);

        $uploadResponse = $this->postJson('/filepond/api/process', [
            'file' => UploadedFile::fake()->create('test.txt', 1),
        ]);

        $uploadResponse->assertStatus(200);
        $serverId = $uploadResponse->content();

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $pathFromServerId = $filepond->getPathFromServerId($serverId);

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
        Storage::disk($diskName)->assertMissing($pathFromServerId);
    }

    /** @test */
    public function test_delete_returns_bad_request_for_an_invalid_server_id()
    {
        $deleteResponse = $this->call(
            'DELETE',
            '/filepond/api/process',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'not-a-valid-server-id'
        );

        $deleteResponse->assertStatus(400);
    }
}
