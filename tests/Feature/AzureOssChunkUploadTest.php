<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\Tests\AzureTestCase;

class AzureOssChunkUploadTest extends AzureTestCase
{
    public function test_real_sdk_stages_and_commits_out_of_order_blocks_with_the_storage_prefix(): void
    {
        $response = $this->post('/filepond/api/process', [], ['Upload-Name' => 'Safety+Association (1).pdf'])->assertOk();
        $id = $response->getContent();
        $path = app(Filepond::class)->getPathFromServerId($id);

        $this->sendChunk($id, 'world', 6, 11)->assertNoContent();
        $this->assertFalse($this->storage->exists($path));
        $this->sendChunk($id, 'hello ', 0, 11)->assertNoContent();

        $this->assertSame('hello world', $this->storage->get($path));
        $requests = $this->azureRequests();
        $this->assertSame(['block', 'block', 'blocklist'], array_column(array_column($requests, 'query'), 'comp'));
        $this->assertSame(['azure-prefix/'.$path], array_values(array_unique(array_column($requests, 'path'))));
        $this->assertSame('world', $requests[0]['body']);
        $this->assertSame('hello ', $requests[1]['body']);
        $blockIds = array_map('strval', iterator_to_array(simplexml_load_string($requests[2]['body'])->Latest, false));
        $this->assertSame([$this->blockId(0), $this->blockId(6)], $blockIds);
        $this->assertSame([], $this->storage->allFiles(config('filepond.chunks_path')));
    }

    public function test_existing_v2_manifest_and_block_ids_are_retained_and_retries_restage_content(): void
    {
        $path = 'filepond/existing/archive.wbt';
        $id = Crypt::encryptString($path);
        $manifestPath = config('filepond.chunks_path').'/'.sha1($path).'/manifest.json';
        $this->sendChunk($id, 'wrong ', 0, 11)->assertNoContent();
        $this->storage->put($manifestPath, json_encode([
            'upload_length' => 11,
            'chunks' => [['offset' => 0, 'size' => 6, 'block_id' => $this->blockId(0)]],
        ]));
        $this->sendChunk($id, 'hello ', 0, 11)->assertNoContent();
        $this->sendChunk($id, 'world', 6, 11)->assertNoContent();
        $this->assertSame('hello world', $this->storage->get($path));
        $this->assertCount(4, $this->azureRequests());
        $this->assertFalse($this->storage->exists($manifestPath));
    }

    public function test_azure_commit_preserves_the_initial_pdf_content_type(): void
    {
        $response = $this->post('/filepond/api/process', ['file' => ['{}']], [
            'Upload-Name' => 'manual.pdf', 'Content-Type' => 'application/pdf',
        ])->assertOk();
        $id = $response->getContent();
        $this->sendChunk($id, '%PDF', 0, 4)->assertNoContent();
        $requests = $this->azureRequests();
        $headers = array_change_key_case($requests[1]['headers']);
        $this->assertSame('application/pdf', $headers['x-ms-blob-content-type'] ?? null);
        $this->assertSame([], $this->storage->allFiles(config('filepond.chunks_path')));
    }

    public function test_zero_byte_azure_upload_commits_an_empty_blob_without_staging_a_block(): void
    {
        $path = 'filepond/empty/example.txt';
        $this->sendChunk(Crypt::encryptString($path), '', 0, 0)->assertNoContent();
        $this->assertTrue($this->storage->exists($path));
        $this->assertSame('', $this->storage->get($path));
        $this->assertSame(['blocklist'], array_column(array_column($this->azureRequests(), 'query'), 'comp'));
        $this->assertSame([], $this->storage->allFiles(config('filepond.chunks_path')));
    }

    public function test_terminal_empty_azure_patch_does_not_stage_an_orphan_block_or_manifest(): void
    {
        $id = Crypt::encryptString('filepond/exact/example.txt');
        $this->sendChunk($id, 'abcd', 0, 4)->assertNoContent();
        $this->sendChunk($id, '', 4, 4)->assertNoContent();
        $this->assertSame('abcd', $this->storage->get('filepond/exact/example.txt'));
        $this->assertCount(2, $this->azureRequests());
        $this->assertSame([], $this->storage->allFiles(config('filepond.chunks_path')));
    }

    public function test_a_failed_commit_keeps_the_manifest_so_the_last_chunk_can_be_retried(): void
    {
        $path = 'filepond/retry/example.txt';
        $id = Crypt::encryptString($path);
        touch($this->azureRoot.'/fail-commit');
        $this->sendChunk($id, 'abcd', 0, 4)->assertStatus(500);
        $this->assertTrue($this->storage->exists(config('filepond.chunks_path').'/'.sha1($path).'/manifest.json'));
        unlink($this->azureRoot.'/fail-commit');
        $this->sendChunk($id, 'abcd', 0, 4)->assertNoContent();
        $this->assertSame('abcd', $this->storage->get($path));
    }

    public function test_binary_uploads_stage_each_byte_once_and_use_a_server_side_commit(): void
    {
        $path = 'filepond/binary/archive.wbt';
        $id = Crypt::encryptString($path);
        $content = random_bytes(2 * 1024 * 1024 + 123);
        foreach (str_split($content, 1024 * 1024) as $index => $part) {
            $this->sendChunk($id, $part, $index * 1024 * 1024, strlen($content))->assertNoContent();
        }
        $requests = $this->azureRequests();
        $this->assertSame(['block', 'block', 'block', 'blocklist'], array_column(array_column($requests, 'query'), 'comp'));
        $this->assertSame(strlen($content), array_sum(array_map(fn ($request) => strlen($request['body']), array_slice($requests, 0, 3))));
        $this->assertSame(hash('sha256', $content), hash('sha256', $this->storage->get($path)));
        $this->assertSame([], $this->storage->allFiles(config('filepond.chunks_path')));
    }

    private function sendChunk(string $id, string $content, int $offset, int $length)
    {
        return $this->call('PATCH', '/filepond/api?patch='.$id, [], [], [], [
            'CONTENT_TYPE' => 'application/offset+octet-stream',
            'HTTP_UPLOAD_OFFSET' => (string) $offset,
            'HTTP_UPLOAD_LENGTH' => (string) $length,
        ], $content);
    }

    private function blockId(int $offset): string
    {
        return base64_encode(str_pad((string) $offset, 20, '0', STR_PAD_LEFT));
    }
}
