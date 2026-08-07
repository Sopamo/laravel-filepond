<?php

namespace Sopamo\LaravelFilepond\Tests\Unit;

use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Sopamo\LaravelFilepond\ChunkAssembler;
use Sopamo\LaravelFilepond\ChunkFileAssembler;
use Sopamo\LaravelFilepond\Exceptions\UploadException;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\ServerIdCodec;
use Sopamo\LaravelFilepond\TemporaryUpload;
use Sopamo\LaravelFilepond\Tests\TestCase;

class StorageFailureTest extends TestCase
{
    public function test_regular_upload_supports_filesystems_that_close_consumed_streams(): void
    {
        $storage = $this->createMock(FilesystemAdapter::class);
        $storage->expects($this->once())
            ->method('put')
            ->willReturnCallback(function (string $path, $contents): bool {
                $this->assertMatchesRegularExpression(
                    '#^filepond/[0-9A-HJKMNP-TV-Z]{26}/manual\+guide\.pdf$#i',
                    $path,
                );
                $this->assertIsResource($contents);
                fclose($contents);

                return true;
            });
        $filesystems = $this->createMock(FilesystemManager::class);
        $filesystems->expects($this->once())
            ->method('disk')
            ->with('local')
            ->willReturn($storage);
        $filepond = new Filepond(
            $filesystems,
            app(ServerIdCodec::class),
            app(ChunkAssembler::class),
        );

        $serverId = $filepond->store(
            UploadedFile::fake()->createWithContent('manual+guide.pdf', 'contents'),
        );

        $this->assertSame('manual+guide.pdf', $filepond->resolveServerId($serverId)->originalName);
    }

    public function test_regular_upload_reports_a_failed_storage_write(): void
    {
        $storage = $this->createMock(FilesystemAdapter::class);
        $storage->expects($this->once())
            ->method('put')
            ->willReturn(false);
        $filesystems = $this->createMock(FilesystemManager::class);
        $filesystems->expects($this->once())
            ->method('disk')
            ->with('local')
            ->willReturn($storage);
        $filepond = new Filepond(
            $filesystems,
            app(ServerIdCodec::class),
            app(ChunkAssembler::class),
        );

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('The uploaded file could not be stored.');

        $filepond->store(UploadedFile::fake()->createWithContent('manual+guide.pdf', 'contents'));
    }

    public function test_chunk_assembly_supports_filesystems_that_close_consumed_streams(): void
    {
        $chunkContents = 'data';
        $chunkStream = fopen('php://temp', 'w+b');
        $this->assertIsResource($chunkStream);
        fwrite($chunkStream, $chunkContents);
        rewind($chunkStream);

        $upload = new TemporaryUpload(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'local',
            'filepond/01ARZ3NDEKTSV4RRFFQ69G5FAV/manual+guide.pdf',
            'manual+guide.pdf',
        );
        $storage = $this->createMock(FilesystemAdapter::class);
        $storage->expects($this->once())
            ->method('readStream')
            ->with('filepond/chunks/'.$upload->id.'/parts/0')
            ->willReturn($chunkStream);
        $storage->expects($this->once())
            ->method('put')
            ->with($upload->path)
            ->willReturnCallback(function (string $path, $contents) use ($upload): bool {
                $this->assertSame($upload->path, $path);
                $this->assertIsResource($contents);
                fclose($contents);

                return true;
            });

        (new ChunkFileAssembler())->assemble(
            $storage,
            $upload,
            'filepond/chunks/'.$upload->id,
            [
                0 => [
                    'size' => strlen($chunkContents),
                    'checksum' => hash('sha256', $chunkContents),
                ],
            ],
            strlen($chunkContents),
        );
    }

    public function test_chunk_upload_reports_a_failed_part_write_without_advancing_the_manifest(): void
    {
        $upload = new TemporaryUpload(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'local',
            'filepond/01ARZ3NDEKTSV4RRFFQ69G5FAV/manual+guide.pdf',
            'manual+guide.pdf',
        );
        $manifest = json_encode([
            'version' => 1,
            'path' => $upload->path,
            'length' => 4,
            'parts' => [],
            'completed' => false,
        ], JSON_THROW_ON_ERROR);
        $storage = $this->createMock(FilesystemAdapter::class);
        $storage->expects($this->once())
            ->method('exists')
            ->willReturn(true);
        $storage->expects($this->once())
            ->method('get')
            ->willReturn($manifest);
        $storage->expects($this->once())
            ->method('put')
            ->with('filepond/chunks/'.$upload->id.'/parts/0', 'data')
            ->willReturn(false);
        $filesystems = $this->createMock(FilesystemManager::class);
        $filesystems->expects($this->exactly(2))
            ->method('disk')
            ->with('local')
            ->willReturn($storage);
        $chunkAssembler = new ChunkAssembler(
            $filesystems,
            app(CacheManager::class),
            new ChunkFileAssembler(),
        );

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('The chunk could not be stored.');

        $chunkAssembler->store($upload, 0, 4, 'data');
    }
}
