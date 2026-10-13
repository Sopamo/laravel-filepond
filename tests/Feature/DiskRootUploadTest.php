<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\Tests\TestCase;

class DiskRootUploadTest extends TestCase
{
    public static function temporaryRoots(): array
    {
        return ['empty root' => [''], 'slash root' => ['/']];
    }

    public static function historicalPathPrefixes(): array
    {
        $cases = [];
        foreach (self::temporaryRoots() as $rootName => [$root]) {
            foreach (['relative' => '', 'slash' => '/', 'double slash' => '//', 'backslash' => '\\'] as $name => $prefix) {
                $cases[$rootName.' '.$name] = [$root, $prefix];
            }
        }

        return $cases;
    }

    #[DataProvider('temporaryRoots')]
    public function test_single_upload_at_disk_root_can_be_resolved_and_reverted(string $root): void
    {
        config(['filepond.temporary_files_path' => $root]);
        $storage = Storage::fake('local');
        $storage->put('keep.txt', 'keep me');

        $id = $this->post('/filepond/api/process', [
            'file' => UploadedFile::fake()->createWithContent('example.txt', 'test'),
        ])->assertOk()->getContent();
        $path = app(Filepond::class)->getPathFromServerId($id);

        $this->assertSame(Crypt::decryptString($id), $path);
        $this->assertSame('test', $storage->get($path));
        $this->call('DELETE', '/filepond/api/process', [], [], [], [], $id)->assertOk();

        $storage->assertMissing($path);
        $this->assertFalse($storage->directoryExists(dirname($path)));
        $this->assertSame('keep me', $storage->get('keep.txt'));
        $this->assertDirectoryExists($storage->path(''));
    }

    #[DataProvider('temporaryRoots')]
    public function test_chunk_upload_at_disk_root_completes_and_can_be_reverted(string $root): void
    {
        config(['filepond.temporary_files_path' => $root]);
        $storage = Storage::fake('local');
        $id = $this->post('/filepond/api/process', [], ['Upload-Name' => 'example.txt'])->assertOk()->getContent();
        $path = app(Filepond::class)->getPathFromServerId($id);
        $parts = config('filepond.chunks_path').'/'.sha1($path);

        $this->assertSame(Crypt::decryptString($id), $path);
        $this->sendChunk($id, 'ab', 0, 4)->assertNoContent();
        $storage->assertExists($parts.'/patch.0');
        $this->sendChunk($id, 'cd', 2, 4)->assertNoContent();
        $this->sendChunk($id, '', 4, 4)->assertNoContent();
        $this->assertSame('abcd', $storage->get($path));
        $this->assertFalse($storage->directoryExists($parts));

        $this->call('DELETE', '/filepond/api/process', [], [], [], [], $id)->assertOk();
        $this->assertSame([], $storage->allFiles());
        $this->assertFalse($storage->directoryExists(dirname($path)));
        $this->assertDirectoryExists($storage->path(''));
    }

    #[DataProvider('historicalPathPrefixes')]
    public function test_historical_root_ids_retain_their_paths_and_existing_chunks(string $root, string $prefix): void
    {
        config(['filepond.temporary_files_path' => $root]);
        $storage = Storage::fake('local');
        $path = $prefix.'upload/archive.wbt';
        $id = Crypt::encryptString($path);
        $parts = config('filepond.chunks_path').'/'.sha1($path);
        $storage->put($parts.'/patch.0', 'ab');

        $this->assertSame($path, app(Filepond::class)->getPathFromServerId($id));
        $this->sendChunk($id, 'cd', 2, 4)->assertNoContent();
        $this->assertSame('abcd', $storage->get($path));
        $this->assertFalse($storage->directoryExists($parts));
    }

    #[DataProvider('historicalPathPrefixes')]
    public function test_reverting_flat_root_ids_preserves_siblings_and_the_empty_disk_root(string $root, string $prefix): void
    {
        config(['filepond.temporary_files_path' => $root]);
        $storage = Storage::fake('local');
        $path = $prefix.'old-file.pdf';
        $storage->put($path, 'delete me');
        $storage->put('keep.pdf', 'keep me');

        $this->call('DELETE', '/filepond/api/process', [], [], [], [], Crypt::encryptString($path))->assertOk();
        $storage->assertMissing($path);
        $this->assertSame('keep me', $storage->get('keep.pdf'));

        // Deleting the last file must preserve the root directory itself.
        $this->call('DELETE', '/filepond/api/process', [], [], [], [], Crypt::encryptString($prefix.'keep.pdf'))->assertOk();
        $this->assertSame([], $storage->allFiles());
        $this->assertDirectoryExists($storage->path(''));
    }

    public static function invalidRootPaths(): array
    {
        $cases = [];
        $paths = [
            'empty path' => '',
            'root itself' => '/',
            'repeated root separators' => '//',
            'parent traversal' => '../outside.pdf',
            'nested traversal' => '/upload/../outside.pdf',
            'backslash traversal' => '\\upload\\..\\outside.pdf',
            'empty filename' => '/upload/',
            'empty directory' => '/upload//file.pdf',
            'dot directory' => '/upload/./file.pdf',
            'null byte' => "/upload/file\0.pdf",
            'control character' => "/upload/file\n.pdf",
            'delete character' => "/upload/file\x7f.pdf",
        ];
        foreach (self::temporaryRoots() as $rootName => [$root]) {
            foreach ($paths as $name => $path) {
                $cases[$rootName.' '.$name] = [$root, $path];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidRootPaths')]
    public function test_revert_rejects_malformed_paths_at_disk_root(string $root, string $path): void
    {
        config(['filepond.temporary_files_path' => $root]);
        $storage = Storage::fake('local');
        $storage->put('outside.pdf', 'keep me');

        $this->call('DELETE', '/filepond/api/process', [], [], [], [], Crypt::encryptString($path))->assertBadRequest();

        $this->assertSame(['outside.pdf'], $storage->allFiles());
        $this->assertSame('keep me', $storage->get('outside.pdf'));
    }

    private function sendChunk(string $id, string $content, int $offset, int $length): \Illuminate\Testing\TestResponse
    {
        return $this->call('PATCH', '/filepond/api', ['patch' => $id], [], [], [
            'HTTP_UPLOAD_OFFSET' => (string) $offset,
            'HTTP_UPLOAD_LENGTH' => (string) $length,
        ], $content);
    }
}
