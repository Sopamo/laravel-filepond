<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\Tests\TestCase;

class UploadCompatibilityTest extends TestCase
{
    public static function filenames(): array
    {
        return [
            ['Safety+Association (1).pdf'],
            ['a+b c%20d%25e.txt'],
            ['Zażółć+gęślą.pdf'],
        ];
    }

    #[DataProvider('filenames')]
    public function test_real_encrypted_paths_survive_chunk_requests_and_application_moves(string $filename): void
    {
        $storage = Storage::fake('local');
        $filepond = app(Filepond::class);

        // Relaunch's header callback sends the name and MIME type, but no initial length.
        $response = $this->post('/filepond/api/process', ['file' => ['{"metadata":true}']], [
            'Upload-Name' => $filename,
            'Content-Type' => 'application/pdf',
        ])->assertOk();
        $id = $response->getContent();
        $path = $filepond->getPathFromServerId($id);
        $this->assertSame($filename, basename($path));
        $this->assertSame($path, Crypt::decryptString($id));
        $this->assertSame($path, $filepond->getPathFromServerId($filepond->getServerIdFromPath($path)));
        $storage->assertMissing($path);

        // FilePond appends the actual Laravel token to its PATCH URL.
        $this->call('PATCH', '/filepond/api?patch='.$id, [], [], [], [
            'HTTP_UPLOAD_OFFSET' => '0',
            'HTTP_UPLOAD_LENGTH' => '4',
            'CONTENT_TYPE' => 'application/offset+octet-stream',
        ], 'test')->assertNoContent();

        $this->assertSame('test', $storage->get($path));
        $this->assertTrue($storage->move($path, 'finished/'.$filename));
        $this->assertSame('test', $storage->get('finished/'.$filename));
    }

    public function test_scalar_metadata_can_initialize_without_an_upload_length(): void
    {
        Storage::fake('local');
        $response = $this->post('/filepond/api/process', ['file' => '{"name":"example"}'])->assertOk();
        $this->assertStringStartsWith('filepond/', app(Filepond::class)->getPathFromServerId($response->getContent()));
    }

    public function test_configured_input_disk_and_trailing_slash_root_are_preserved(): void
    {
        config(['filepond.input_name' => 'attachment', 'filepond.temporary_files_disk' => 'custom',
            'filepond.temporary_files_path' => 'custom/uploads/', 'filepond.chunks_path' => 'custom/parts']);
        $storage = Storage::fake('custom');
        $response = $this->post('/filepond/api/process', [
            'attachment' => UploadedFile::fake()->createWithContent('file.txt', 'test'),
        ])->assertOk();
        $path = app(Filepond::class)->getPathFromServerId($response->getContent());
        $this->assertStringStartsWith('custom/uploads/', $path);
        $this->assertSame('test', $storage->get($path));
    }

    public function test_windows_path_separators_round_trip_without_changing_the_encrypted_path(): void
    {
        $path = 'filepond\\upload\\report.pdf';
        $filepond = app(Filepond::class);
        $this->assertSame($path, $filepond->getPathFromServerId(Crypt::encryptString($path)));
    }

    public function test_invalid_configuration_reports_the_setting_instead_of_casting_it(): void
    {
        $this->withoutExceptionHandling();
        config(['filepond.input_name' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filepond.input_name');

        $this->post('/filepond/api/process');
    }

    public function test_array_file_input_stores_the_first_file_with_its_original_name(): void
    {
        $storage = Storage::fake('local');
        $response = $this->post('/filepond/api/process', [
            'file' => [UploadedFile::fake()->createWithContent('Safety+Association (1).pdf', 'test')],
        ])->assertOk();
        $path = app(Filepond::class)->getPathFromServerId($response->getContent());
        $this->assertSame('Safety+Association (1).pdf', basename($path));
        $this->assertSame('test', $storage->get($path));
    }

    public function test_an_existing_v2_upload_continues_and_retries_replace_damaged_parts(): void
    {
        $storage = Storage::fake('local');
        $path = 'filepond/existing/archive.wbt';
        $id = Crypt::encryptString($path);
        $parts = config('filepond.chunks_path').'/'.sha1($path);
        $storage->put($parts.'/patch.0', 'damaged');

        foreach ([[0, 'hello '], [6, 'world']] as [$offset, $content]) {
            $this->call('PATCH', '/filepond/api?patch='.$id, [], [], [], [
                'HTTP_UPLOAD_OFFSET' => (string) $offset,
                'HTTP_UPLOAD_LENGTH' => '11',
            ], $content)->assertNoContent();
        }

        $this->assertSame('hello world', $storage->get($path));
        $this->assertSame([], $storage->allFiles($parts));
    }

    public function test_filepond_terminal_empty_patch_preserves_the_completed_file(): void
    {
        $storage = Storage::fake('local');
        $path = 'filepond/exact-multiple/example.txt';
        $id = Crypt::encryptString($path);

        foreach ([[0, 'abcd'], [4, 'efgh'], [8, '']] as [$offset, $content]) {
            $this->call('PATCH', '/filepond/api?patch='.$id, [], [], [], [
                'HTTP_UPLOAD_OFFSET' => (string) $offset,
                'HTTP_UPLOAD_LENGTH' => '8',
            ], $content)->assertNoContent();
        }

        $this->assertSame('abcdefgh', $storage->get($path));
        $this->assertSame([], $storage->allFiles(config('filepond.chunks_path')));
    }

    public function test_zero_byte_chunk_upload_creates_an_empty_file_and_cleans_up(): void
    {
        $storage = Storage::fake('local');
        $path = 'filepond/empty/example.txt';
        $this->call('PATCH', '/filepond/api?patch='.Crypt::encryptString($path), [], [], [], [
            'HTTP_UPLOAD_OFFSET' => '0', 'HTTP_UPLOAD_LENGTH' => '0',
        ], '')->assertNoContent();
        $storage->assertExists($path);
        $this->assertSame('', $storage->get($path));
        $this->assertSame([], $storage->allFiles(config('filepond.chunks_path')));
    }

    public function test_reverting_a_flat_historical_path_preserves_other_uploads(): void
    {
        $storage = Storage::fake('local');
        $path = 'filepond/old-file.pdf';
        $storage->put($path, 'delete me');
        $storage->put('filepond/keep.pdf', 'keep me');
        $storage->put('filepond/another/file.pdf', 'keep this too');
        $this->call('DELETE', '/filepond/api/process', [], [], [], [], Crypt::encryptString($path))->assertOk();
        $storage->assertMissing($path);
        $storage->assertExists('filepond/keep.pdf');
        $storage->assertExists('filepond/another/file.pdf');
    }

    public static function invalidPaths(): array
    {
        return [
            'sibling root' => ['filepond-other/file.pdf'],
            'parent traversal' => ['filepond/../outside.pdf'],
            'root itself' => ['filepond'],
            'nested traversal' => ['filepond/upload/../../outside.pdf'],
            'backslash traversal' => ['filepond/upload\\..\\outside.pdf'],
            'empty filename' => ['filepond/'],
            'empty directory' => ['filepond//file.pdf'],
            'dot directory' => ['filepond/./file.pdf'],
            'null byte' => ["filepond/upload/file\0.pdf"],
            'control character' => ["filepond/upload/file\n.pdf"],
            'delete character' => ["filepond/upload/file\x7f.pdf"],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function test_revert_rejects_invalid_temporary_upload_paths(string $path): void
    {
        Storage::fake('local');
        $this->call('DELETE', '/filepond/api/process', [], [], [], [], Crypt::encryptString($path))->assertBadRequest();
    }
}
