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
}
