<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Crypt;
use Sopamo\LaravelFilepond\ChunkAssembler;
use Sopamo\LaravelFilepond\Exceptions\UploadException;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\ServerIdCodec;

class FilepondRevertTest extends FilepondFeatureTestCase
{
    public function test_revert_is_idempotent_and_only_removes_the_selected_upload(): void
    {
        $firstId = $this->initializeChunkUpload('first.txt', 8);
        $secondId = $this->initializeChunkUpload('second.txt', 8);
        $first = app(Filepond::class)->resolveServerId($firstId);
        $second = app(Filepond::class)->resolveServerId($secondId);

        $this->call('DELETE', '/filepond/api/process', [], [], [], [], $firstId)->assertOk();
        $this->call('DELETE', '/filepond/api/process', [], [], [], [], $firstId)->assertOk();

        $this->storage()->assertMissing('filepond/chunks/'.$first->id);
        $this->storage()->assertExists('filepond/chunks/'.$second->id.'/manifest.json');
    }

    public function test_revert_holds_the_upload_lock_while_deleting_the_upload_and_chunk_state(): void
    {
        $serverId = $this->initializeChunkUpload('locked.txt', 8);
        $upload = app(Filepond::class)->resolveServerId($serverId);
        $deletedDirectories = [];
        $storage = $this->createMock(FilesystemAdapter::class);
        $storage->expects($this->exactly(2))
            ->method('deleteDirectory')
            ->willReturnCallback(function (string $path) use ($upload, &$deletedDirectories): bool {
                $deletedDirectories[] = $path;
                $competingLock = $this->lockStore()->lock(
                    'filepond:'.$upload->disk.':'.$upload->id,
                    60,
                );
                $competingLockAcquired = $competingLock->get();
                if ($competingLockAcquired) {
                    $competingLock->release();
                }

                $this->assertFalse($competingLockAcquired);

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

        $filepond->revert($serverId);

        $this->assertSame([
            'filepond/'.$upload->id,
            'filepond/chunks/'.$upload->id,
        ], $deletedDirectories);
    }

    public function test_deprecated_path_server_ids_round_trip_and_revert_without_enabling_v2_ids(): void
    {
        $this->storage()->put('filepond/legacy.txt', 'legacy');
        $this->storage()->put('filepond/keep.txt', 'keep');
        $this->storage()->put('filepond/legacy-directory/file.txt', 'legacy directory');
        $this->storage()->put('filepond/legacy-directory/sibling.txt', 'same legacy directory');
        $this->storage()->put('filepond/other-directory/file.txt', 'other directory');
        $filepond = app(Filepond::class);

        $directServerId = $filepond->getServerIdFromPath('filepond/legacy.txt');
        $this->assertSame('filepond/legacy.txt', Crypt::decryptString($directServerId));
        $this->assertTrue($filepond->resolveServerId($directServerId)->legacy);
        $this->assertSame('filepond/legacy.txt', $filepond->getPathFromServerId($directServerId));
        $filepond->revert($directServerId);

        $this->storage()->assertMissing('filepond/legacy.txt');
        $this->storage()->assertExists('filepond/keep.txt');

        $nestedServerId = $filepond->getServerIdFromPath('filepond/legacy-directory/file.txt');
        $this->assertTrue($filepond->resolveServerId($nestedServerId)->legacy);
        $filepond->revert($nestedServerId);

        $this->storage()->assertMissing('filepond/legacy-directory');
        $this->storage()->assertExists('filepond/other-directory/file.txt');
    }

    public function test_revert_fails_when_the_storage_adapter_cannot_delete_the_upload(): void
    {
        $serverId = $this->initializeChunkUpload('undeletable.txt', 8);
        $upload = app(Filepond::class)->resolveServerId($serverId);
        $uploadDirectory = 'filepond/'.$upload->id;
        $storage = $this->createMock(FilesystemAdapter::class);
        $storage->expects($this->once())
            ->method('deleteDirectory')
            ->with($uploadDirectory)
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
        $this->expectExceptionMessage('The temporary upload directory could not be deleted.');

        $filepond->revert($serverId);
    }
}
