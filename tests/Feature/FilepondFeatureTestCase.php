<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Sopamo\LaravelFilepond\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

abstract class FilepondFeatureTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('filepond.temporary_files_disk', 'local');
        config()->set('filepond.maximum_upload_size', 1024);
        config()->set('filepond.maximum_chunk_size', 512);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function unsafeStorageRootProvider(): array
    {
        return [
            'empty temporary root' => ['temporary_files_path', '', false],
            'absolute temporary root' => ['temporary_files_path', '/', false],
            'temporary traversal' => ['temporary_files_path', '../filepond', false],
            'temporary dot segment' => ['temporary_files_path', 'filepond/./temporary', false],
            'temporary NUL segment' => ['temporary_files_path', "filepond/\0temporary", false],
            'empty chunks root' => ['chunks_path', '', true],
            'absolute chunks root' => ['chunks_path', '/filepond/chunks', true],
            'chunks traversal' => ['chunks_path', 'filepond/../chunks', true],
        ];
    }

    protected function initializeChunkUpload(string $name, int $length): string
    {
        return $this->post('/filepond/api/process', [], [
            'Upload-Name' => $name,
            'Upload-Length' => (string) $length,
        ])->assertOk()->content();
    }

    protected function storage(): FilesystemAdapter
    {
        $storage = Storage::disk('local');
        assert($storage instanceof FilesystemAdapter);

        return $storage;
    }

    protected function storageContents(string $path): string
    {
        $contents = $this->storage()->get($path);
        assert(is_string($contents));

        return $contents;
    }

    protected function lockStore(): LockProvider
    {
        $store = app(CacheManager::class)->store(config('filepond.lock_store'))->getStore();
        assert($store instanceof LockProvider);

        return $store;
    }

    /** @return TestResponse<Response> */
    protected function patchChunk(string $serverId, string $content, int $offset, int $length): TestResponse
    {
        return $this->call('PATCH', '/filepond/api', ['patch' => $serverId], [], [], [
            'HTTP_UPLOAD_OFFSET' => (string) $offset,
            'HTTP_UPLOAD_LENGTH' => (string) $length,
        ], $content);
    }
}
