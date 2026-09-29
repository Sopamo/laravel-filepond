<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\Tests\TestCase;

class ChunkUploadTest extends TestCase
{
    public static function invalidChunks(): array
    {
        return [
            'empty beyond length' => ['', 2, 1],
            'empty at start' => ['', 0, 4],
            'empty in middle' => ['', 2, 4],
            'data beyond length' => ['x', 5, 4],
            'data at end' => ['x', 4, 4],
            'overlong data' => ['abc', 2, 4],
            'data for empty upload' => ['x', 0, 0],
            'offset for empty upload' => ['', 1, 0],
            'integer boundary' => ['xx', PHP_INT_MAX - 1, PHP_INT_MAX],
        ];
    }

    #[DataProvider('invalidChunks')]
    public function test_invalid_chunks_are_rejected_without_storage_changes(string $content, int $offset, int $length): void
    {
        $storage = Storage::fake('local');
        $id = $this->initializeChunkUpload('example.txt', $length);

        $this->sendChunk($id, $content, $offset, $length)->assertStatus(400);

        $this->assertSame([], $storage->allFiles());
    }

    public function test_repeated_empty_invalid_chunks_cannot_accumulate_parts(): void
    {
        $storage = Storage::fake('local');
        $id = $this->initializeChunkUpload('example.txt', 1);
        for ($offset = 2; $offset <= 20; $offset++) {
            $this->sendChunk($id, '', $offset, 1)->assertStatus(400);
        }
        $this->sendChunk($id, '', 2, 1)->assertStatus(400);
        $this->assertSame([], $storage->allFiles());

        $this->sendChunk($id, 'x', 0, 1)->assertNoContent();
        $this->assertSame('x', $storage->get(app(Filepond::class)->getPathFromServerId($id)));
    }

    public function test_zero_byte_local_upload_still_completes(): void
    {
        $storage = Storage::fake('local');
        $id = $this->initializeChunkUpload('empty.txt', 0);
        $this->sendChunk($id, '', 0, 0)->assertNoContent();
        $this->assertSame('', $storage->get(app(Filepond::class)->getPathFromServerId($id)));
        $this->assertSame([], $storage->allFiles(config('filepond.chunks_path')));
    }

    public function test_invalid_chunks_preserve_existing_parts_and_allow_completion(): void
    {
        $storage = Storage::fake('local');
        $id = $this->initializeChunkUpload('example.txt', 4);
        $path = app(Filepond::class)->getPathFromServerId($id);
        $this->sendChunk($id, 'ab', 0, 4)->assertNoContent();
        $this->sendChunk($id, '', 0, 4)->assertStatus(400);
        $this->sendChunk($id, 'wrong', 0, 4)->assertStatus(400);
        $this->assertSame('ab', $storage->get($this->buildChunkStoragePath($path).'/patch.0'));
        $this->sendChunk($id, 'cd', 2, 4)->assertNoContent();
        $this->assertSame('abcd', $storage->get($path));
    }

    public function test_overflowing_chunk_headers_are_rejected_and_padded_headers_remain_valid(): void
    {
        $storage = Storage::fake('local');
        $id = $this->initializeChunkUpload('example.txt', 4);
        foreach (['HTTP_UPLOAD_OFFSET', 'HTTP_UPLOAD_LENGTH'] as $header) {
            foreach ([(string) PHP_INT_MAX.'0', '000'.PHP_INT_MAX.'0'] as $value) {
                $headers = ['HTTP_UPLOAD_OFFSET' => '0', 'HTTP_UPLOAD_LENGTH' => '4', 'HTTP_ACCEPT' => 'application/json'];
                $headers[$header] = $value;
                $this->call('PATCH', '/filepond/api', ['patch' => $id], [], [], $headers, 'test')->assertStatus(400);
            }
        }
        $this->assertSame([], $storage->allFiles());

        $this->call('PATCH', '/filepond/api', ['patch' => $id], [], [], [
            'HTTP_UPLOAD_OFFSET' => ' 000 ',
            'HTTP_UPLOAD_LENGTH' => ' 004 ',
        ], 'test')->assertNoContent();
        $this->assertSame('test', $storage->get(app(Filepond::class)->getPathFromServerId($id)));

        $maxId = $this->initializeChunkUpload('max.txt', PHP_INT_MAX);
        $this->call('PATCH', '/filepond/api', ['patch' => $maxId], [], [], [
            'HTTP_UPLOAD_OFFSET' => '000'.PHP_INT_MAX,
            'HTTP_UPLOAD_LENGTH' => '000'.PHP_INT_MAX,
        ], '')->assertStatus(409);
    }

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

    public function test_out_of_order_chunk_upload_is_assembled_once_coverage_is_complete()
    {
        $diskName = config('filepond.temporary_files_disk', 'local');

        Storage::fake($diskName);

        $serverId = $this->initializeChunkUpload('archive.wbt', 11);

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $finalFilePath = $filepond->getPathFromServerId($serverId);

        $this->sendChunk($serverId, 'world', 6, 11)->assertStatus(204);
        Storage::disk($diskName)->assertMissing($finalFilePath);

        $this->sendChunk($serverId, 'hello ', 0, 11)->assertStatus(204);

        Storage::disk($diskName)->assertExists($finalFilePath);
        $this->assertSame('hello world', Storage::disk($diskName)->get($finalFilePath));
    }

    public function test_chunk_initialization_does_not_create_a_placeholder_file()
    {
        $diskName = config('filepond.temporary_files_disk', 'local');

        Storage::fake($diskName);

        $serverId = $this->initializeChunkUpload('archive.wbt', 11);

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $finalFilePath = $filepond->getPathFromServerId($serverId);

        Storage::disk($diskName)->assertMissing($finalFilePath);
    }

    public function test_chunk_upload_with_gap_is_not_assembled()
    {
        $diskName = config('filepond.temporary_files_disk', 'local');

        Storage::fake($diskName);

        $serverId = $this->initializeChunkUpload('archive.wbt', 11);

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $finalFilePath = $filepond->getPathFromServerId($serverId);
        $chunkStoragePath = $this->buildChunkStoragePath($finalFilePath);

        $this->sendChunk($serverId, 'hello', 0, 11)->assertStatus(204);
        $this->sendChunk($serverId, 'world', 6, 11)->assertStatus(204);

        Storage::disk($diskName)->assertMissing($finalFilePath);
        Storage::disk($diskName)->assertExists($chunkStoragePath.DIRECTORY_SEPARATOR.'patch.0');
        Storage::disk($diskName)->assertExists($chunkStoragePath.DIRECTORY_SEPARATOR.'patch.6');
    }

    public function test_chunk_upload_with_overlap_is_not_assembled()
    {
        $diskName = config('filepond.temporary_files_disk', 'local');

        Storage::fake($diskName);

        $serverId = $this->initializeChunkUpload('archive.wbt', 11);

        /** @var Filepond $filepond */
        $filepond = app(Filepond::class);
        $finalFilePath = $filepond->getPathFromServerId($serverId);
        $chunkStoragePath = $this->buildChunkStoragePath($finalFilePath);

        $this->sendChunk($serverId, 'hello ', 0, 11)->assertStatus(204);
        $this->sendChunk($serverId, 'world', 5, 11)->assertStatus(204);

        Storage::disk($diskName)->assertMissing($finalFilePath);
        Storage::disk($diskName)->assertExists($chunkStoragePath.DIRECTORY_SEPARATOR.'patch.0');
        Storage::disk($diskName)->assertExists($chunkStoragePath.DIRECTORY_SEPARATOR.'patch.5');
    }

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

    public function test_chunk_upload_returns_bad_request_for_invalid_server_id()
    {
        $this->sendChunk('not-a-valid-server-id', 'test', 0, 4)->assertStatus(400);
    }

    public function test_terminal_empty_patch_before_uploading_data_returns_conflict(): void
    {
        $storage = Storage::fake('local');
        $id = $this->initializeChunkUpload('example.txt', 4);

        $this->sendChunk($id, '', 4, 4)
            ->assertStatus(409)
            ->assertJsonPath('message', 'The uploaded file is incomplete or no longer available.');

        $this->assertSame([], $storage->allFiles());
    }

    public function test_rejected_terminal_patch_preserves_parts_for_completion(): void
    {
        $storage = Storage::fake('local');
        $id = $this->initializeChunkUpload('example.txt', 11);
        $path = app(Filepond::class)->getPathFromServerId($id);
        $parts = $this->buildChunkStoragePath($path);

        $this->sendChunk($id, 'hello ', 0, 11)->assertNoContent();
        $this->sendChunk($id, '', 11, 11)->assertStatus(409);
        $this->assertSame('hello ', $storage->get($parts.'/patch.0'));
        $storage->assertMissing($path);

        $this->sendChunk($id, 'world', 6, 11)->assertNoContent();
        $this->sendChunk($id, '', 11, 11)->assertNoContent();
        $this->assertSame('hello world', $storage->get($path));
        $this->assertSame([], $storage->allFiles($parts));
    }

    public function test_terminal_empty_patch_after_final_file_loss_returns_conflict(): void
    {
        $storage = Storage::fake('local');
        $id = $this->initializeChunkUpload('example.txt', 4);
        $path = app(Filepond::class)->getPathFromServerId($id);

        $this->sendChunk($id, 'test', 0, 4)->assertNoContent();
        $storage->delete($path);
        $this->sendChunk($id, '', 4, 4)->assertStatus(409);

        $storage->assertMissing($path);
        $this->assertSame([], $storage->allFiles(config('filepond.chunks_path')));
    }

    public function test_terminal_empty_patch_rejects_a_final_file_with_the_wrong_size(): void
    {
        $storage = Storage::fake('local');
        $id = $this->initializeChunkUpload('example.txt', 4);
        $path = app(Filepond::class)->getPathFromServerId($id);

        $this->sendChunk($id, 'test', 0, 4)->assertNoContent();
        $storage->put($path, 'x');
        $this->sendChunk($id, '', 4, 4)->assertStatus(409);

        $this->assertSame('x', $storage->get($path));
        $this->assertSame([], $storage->allFiles(config('filepond.chunks_path')));
    }

    public function test_last_data_chunk_retry_does_not_recreate_chunk_files(): void
    {
        $storage = Storage::fake('local');
        $id = $this->initializeChunkUpload('example.txt', 11);
        $path = app(Filepond::class)->getPathFromServerId($id);

        $this->sendChunk($id, 'hello ', 0, 11)->assertNoContent();
        $this->sendChunk($id, 'world', 6, 11)->assertNoContent();
        $this->sendChunk($id, 'world', 6, 11)->assertNoContent();

        $this->assertSame('hello world', $storage->get($path));
        $this->assertSame([], $storage->allFiles(config('filepond.chunks_path')));
    }

    public function test_terminal_patch_with_an_invalid_server_id_still_returns_bad_request(): void
    {
        $this->sendChunk('not-a-valid-server-id', '', 4, 4)->assertStatus(400);
    }

    public function test_invalid_chunk_offset_still_returns_bad_request(): void
    {
        Storage::fake('local');
        $id = $this->initializeChunkUpload('example.txt', 4);

        $this->sendChunk($id, 'test', -1, 4)->assertStatus(400);
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
                'HTTP_ACCEPT' => 'application/json',
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
