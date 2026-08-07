<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Sopamo\LaravelFilepond\Filepond;

class FilepondUploadTest extends FilepondFeatureTestCase
{
    public function test_single_upload_preserves_the_original_filename_inside_an_isolated_directory(): void
    {
        $response = $this->post('/filepond/api/process', [
            'file' => UploadedFile::fake()->create('Safety+guide #1.pdf', 1),
        ]);

        $response->assertOk();
        $upload = app(Filepond::class)->resolveServerId($response->content());

        $this->assertSame('Safety+guide #1.pdf', $upload->originalName);
        $this->assertMatchesRegularExpression(
            '#^filepond/[0-9A-HJKMNP-TV-Z]{26}/Safety\+guide \#1\.pdf$#i',
            $upload->path,
        );
        $this->storage()->assertExists($upload->path);
    }

    #[DataProvider('originalFilenameProvider')]
    public function test_original_filenames_are_preserved_without_allowing_directory_traversal(
        string $originalName,
        string $expectedFilename,
    ): void {
        $response = $this->post('/filepond/api/process', [
            'file' => UploadedFile::fake()->create($originalName, 1),
        ]);

        $response->assertOk();
        $upload = app(Filepond::class)->resolveServerId($response->content());

        $this->assertSame($expectedFilename, $upload->originalName);
        $this->assertSame($expectedFilename, basename($upload->path));
        $this->assertMatchesRegularExpression(
            '#^filepond/[0-9A-HJKMNP-TV-Z]{26}/'.preg_quote($expectedFilename, '#').'$#i',
            $upload->path,
        );
    }

    /** @return array<string, array{string, string}> */
    public static function originalFilenameProvider(): array
    {
        return [
            'plus' => ['Safety+guide.pdf', 'Safety+guide.pdf'],
            'spaces and unicode' => ['résumé final.pdf', 'résumé final.pdf'],
            'percent' => ['100% complete.pdf', '100% complete.pdf'],
            'hash' => ['chapter#1.pdf', 'chapter#1.pdf'],
            'question mark' => ['question?.txt', 'question?.txt'],
            'forward-slash traversal' => ['../path.jpg', 'path.jpg'],
            'backslash traversal' => ['..\\path.jpg', 'path.jpg'],
            'no extension' => ['README', 'README'],
            'previously reserved metadata name' => ['.filepond-upload.json', '.filepond-upload.json'],
        ];
    }

    public function test_multiple_file_input_keeps_version_two_first_file_behavior(): void
    {
        $response = $this->post('/filepond/api/process', [
            'file' => [
                UploadedFile::fake()->create('one.txt', 1),
                UploadedFile::fake()->create('two.txt', 1),
            ],
        ]);

        $response->assertOk();
        $upload = app(Filepond::class)->resolveServerId($response->content());

        $this->assertSame('one.txt', $upload->originalName);
        $this->storage()->assertExists($upload->path);
    }

    public function test_regular_upload_larger_than_the_configured_limit_is_rejected(): void
    {
        $response = $this->postJson('/filepond/api/process', [
            'file' => UploadedFile::fake()->createWithContent('too-large.pdf', str_repeat('a', 1025)),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->storage()->assertDirectoryEmpty('filepond');
    }

    public function test_regular_upload_with_filepond_metadata_still_enforces_the_file_size_limit(): void
    {
        $response = $this->call(
            'POST',
            '/filepond/api/process',
            ['file' => '{}'],
            [],
            ['file' => UploadedFile::fake()->create('too-large.pdf', 2)],
            [
                'HTTP_UPLOAD_LENGTH' => '2048',
                'HTTP_ACCEPT' => 'application/json',
            ],
        );

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->storage()->assertDirectoryEmpty('filepond');
    }

    public function test_chunk_initialization_without_a_declared_length_keeps_version_two_behavior(): void
    {
        $response = $this->post('/filepond/api/process');
        $response->assertOk();
        $upload = app(Filepond::class)->resolveServerId($response->content());

        $this->assertSame('upload', $upload->originalName);
        $this->patchChunk($response->content(), 'data', 0, 4)
            ->assertNoContent()
            ->assertHeader('Upload-Offset', '4');
        $this->assertSame('data', $this->storageContents($upload->path));
    }

    public function test_validation_failure_without_a_json_accept_header_returns_a_plain_422_response(): void
    {
        $response = $this->post('/filepond/api/process', ['file' => 'not-an-upload'])
            ->assertStatus(422);

        $this->assertSame(
            'text/plain; charset=utf-8',
            strtolower((string) $response->headers->get('Content-Type')),
        );
    }

    #[DataProvider('unsafeStorageRootProvider')]
    public function test_uploads_reject_unsafe_storage_roots_before_writing(
        string $configurationKey,
        string $configuredRoot,
        bool $chunkUpload,
    ): void {
        config()->set('filepond.'.$configurationKey, $configuredRoot);

        $response = $chunkUpload
            ? $this->post('/filepond/api/process', [], ['Upload-Name' => 'unsafe.txt', 'Upload-Length' => '4'])
            : $this->post('/filepond/api/process', ['file' => UploadedFile::fake()->create('unsafe.txt', 1)]);

        $response->assertStatus(500);
        $this->assertSame([], $this->storage()->allFiles());
    }
}
