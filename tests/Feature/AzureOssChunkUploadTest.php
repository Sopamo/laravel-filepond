<?php

namespace AzureOss\Storage\BlobFlysystem {
    class AzureBlobStorageAdapter
    {
        /**
         * @var mixed
         */
        public $containerClient;

        /**
         * @var mixed
         */
        public $prefixer;

        /**
         * @param mixed $containerClient
         * @param mixed $prefixer
         */
        public function __construct($containerClient, $prefixer)
        {
            $this->containerClient = $containerClient;
            $this->prefixer = $prefixer;
        }
    }
}

namespace Sopamo\LaravelFilepond\Tests\Feature {
    use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
    use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
    use Illuminate\Support\Facades\Storage;
    use League\Flysystem\Config;
    use League\Flysystem\FileAttributes;
    use League\Flysystem\Filesystem;
    use League\Flysystem\FilesystemAdapter;
    use League\Flysystem\Local\LocalFilesystemAdapter;
    use League\Flysystem\PathPrefixer;
    use Sopamo\LaravelFilepond\Filepond;
    use Sopamo\LaravelFilepond\Tests\TestCase;
    use Sopamo\LaravelFilepond\Uploads\AzureBlockBlobClient;
    use Sopamo\LaravelFilepond\Uploads\AzureBlockBlobContainerClient;

    class AzureOssChunkUploadTest extends TestCase
    {
        /** @test */
        public function test_wrapped_azure_oss_chunk_upload_stages_blocks_and_commits_them_without_patch_files()
        {
            $temporaryStorageRoot = $this->createTemporaryDirectory('laravel-filepond-azure-oss');
            $blobPrefix = 'azure-prefix';
            $prefixedStorageRoot = $temporaryStorageRoot.DIRECTORY_SEPARATOR.$blobPrefix;
            $containerClient = new FakeAzureOssContainerClient($temporaryStorageRoot);
            $diskName = $this->registerWrappedAzureOssDisk($containerClient, $prefixedStorageRoot, $blobPrefix);

            config([
                'filepond.temporary_files_disk' => $diskName,
            ]);

            $serverId = $this->initializeChunkUpload('archive.wbt', 11);

            /** @var Filepond $filepond */
            $filepond = app(Filepond::class);
            $finalFilePath = $filepond->getPathFromServerId($serverId);
            $chunkStoragePath = $this->buildChunkStoragePath($finalFilePath);
            $prefixedBlobPath = $blobPrefix.'/'.$finalFilePath;

            $this->sendChunk($serverId, 'hello ', 0, 11)->assertStatus(204);
            $this->sendChunk($serverId, 'world', 6, 11)->assertStatus(204);

            $blockBlobClient = $containerClient->getExistingBlockBlobClient($prefixedBlobPath);

            $this->assertNotNull($blockBlobClient);
            $this->assertCount(2, $blockBlobClient->stagedBlocks);
            $this->assertSame(
                [
                    $this->buildAzureOssBlockId(0),
                    $this->buildAzureOssBlockId(6),
                ],
                $blockBlobClient->committedBlockLists[0]
            );
            $this->assertSame('hello world', Storage::disk($diskName)->get($finalFilePath));
            $this->assertFileDoesNotExist($prefixedStorageRoot.DIRECTORY_SEPARATOR.$chunkStoragePath.DIRECTORY_SEPARATOR.'patch.0');
            $this->assertFileDoesNotExist($prefixedStorageRoot.DIRECTORY_SEPARATOR.$chunkStoragePath.DIRECTORY_SEPARATOR.'manifest.json');
        }

        /** @test */
        public function test_wrapped_azure_oss_chunk_upload_commits_blocks_in_offset_order()
        {
            $temporaryStorageRoot = $this->createTemporaryDirectory('laravel-filepond-azure-oss');
            $blobPrefix = 'azure-prefix';
            $prefixedStorageRoot = $temporaryStorageRoot.DIRECTORY_SEPARATOR.$blobPrefix;
            $containerClient = new FakeAzureOssContainerClient($temporaryStorageRoot);
            $diskName = $this->registerWrappedAzureOssDisk($containerClient, $prefixedStorageRoot, $blobPrefix);

            config([
                'filepond.temporary_files_disk' => $diskName,
            ]);

            $serverId = $this->initializeChunkUpload('archive.wbt', 11);

            /** @var Filepond $filepond */
            $filepond = app(Filepond::class);
            $finalFilePath = $filepond->getPathFromServerId($serverId);
            $prefixedBlobPath = $blobPrefix.'/'.$finalFilePath;

            $this->sendChunk($serverId, 'world', 6, 11)->assertStatus(204);
            $this->sendChunk($serverId, 'hello ', 0, 11)->assertStatus(204);

            $blockBlobClient = $containerClient->getExistingBlockBlobClient($prefixedBlobPath);

            $this->assertNotNull($blockBlobClient);
            $this->assertSame(
                [
                    $this->buildAzureOssBlockId(0),
                    $this->buildAzureOssBlockId(6),
                ],
                $blockBlobClient->committedBlockLists[0]
            );
            $this->assertSame('hello world', Storage::disk($diskName)->get($finalFilePath));
        }

        private function registerWrappedAzureOssDisk(
            FakeAzureOssContainerClient $containerClient,
            string $prefixedStorageRoot,
            string $blobPrefix
        ): string {
            $driverName = 'wrapped-azure-oss-driver-'.uniqid('', true);
            $diskName = 'wrapped-azure-oss-disk-'.uniqid('', true);

            Storage::extend($driverName, function () use ($containerClient, $prefixedStorageRoot, $blobPrefix) {
                if (!is_dir($prefixedStorageRoot) && !mkdir($prefixedStorageRoot, 0777, true) && !is_dir($prefixedStorageRoot)) {
                    throw new \RuntimeException('Could not create a local root for the wrapped AzureOss disk test.');
                }

                $localAdapter = new LocalFilesystemAdapter($prefixedStorageRoot);
                $wrappedAdapter = new WrappedAzureOssAdapterStub(
                    $localAdapter,
                    new AzureBlobStorageAdapter($containerClient, new PathPrefixer($blobPrefix))
                );
                $filesystem = new Filesystem($localAdapter);

                return new LaravelFilesystemAdapter($filesystem, $wrappedAdapter, [
                    'root' => $prefixedStorageRoot,
                ]);
            });

            config([
                'filesystems.disks.'.$diskName => [
                    'driver' => $driverName,
                    'root' => $prefixedStorageRoot,
                ],
            ]);

            return $diskName;
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
                    'HTTP_UPLOAD_OFFSET' => (string) $offset,
                    'HTTP_UPLOAD_LENGTH' => (string) $uploadLength,
                ],
                $chunkContent
            );
        }

        private function buildAzureOssBlockId(int $offset): string
        {
            return base64_encode(str_pad((string) $offset, 20, '0', STR_PAD_LEFT));
        }

        private function buildChunkStoragePath(string $finalFilePath): string
        {
            return config('filepond.chunks_path').DIRECTORY_SEPARATOR.sha1($finalFilePath);
        }
    }

    class WrappedAzureOssAdapterStub implements FilesystemAdapter
    {
        /**
         * @var FilesystemAdapter
         */
        private $delegatedAdapter;

        /**
         * @var mixed
         */
        private $innerAdapter;

        /**
         * @param mixed $innerAdapter
         */
        public function __construct(FilesystemAdapter $delegatedAdapter, $innerAdapter)
        {
            $this->delegatedAdapter = $delegatedAdapter;
            $this->innerAdapter = $innerAdapter;
        }

        public function fileExists(string $path): bool
        {
            return $this->delegatedAdapter->fileExists($path);
        }

        public function directoryExists(string $path): bool
        {
            return $this->delegatedAdapter->directoryExists($path);
        }

        public function write(string $path, string $contents, Config $config): void
        {
            $this->delegatedAdapter->write($path, $contents, $config);
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            $this->delegatedAdapter->writeStream($path, $contents, $config);
        }

        public function read(string $path): string
        {
            return $this->delegatedAdapter->read($path);
        }

        public function readStream(string $path)
        {
            return $this->delegatedAdapter->readStream($path);
        }

        public function delete(string $path): void
        {
            $this->delegatedAdapter->delete($path);
        }

        public function deleteDirectory(string $path): void
        {
            $this->delegatedAdapter->deleteDirectory($path);
        }

        public function createDirectory(string $path, Config $config): void
        {
            $this->delegatedAdapter->createDirectory($path, $config);
        }

        public function setVisibility(string $path, string $visibility): void
        {
            $this->delegatedAdapter->setVisibility($path, $visibility);
        }

        public function visibility(string $path): FileAttributes
        {
            return $this->delegatedAdapter->visibility($path);
        }

        public function mimeType(string $path): FileAttributes
        {
            return $this->delegatedAdapter->mimeType($path);
        }

        public function lastModified(string $path): FileAttributes
        {
            return $this->delegatedAdapter->lastModified($path);
        }

        public function fileSize(string $path): FileAttributes
        {
            return $this->delegatedAdapter->fileSize($path);
        }

        public function listContents(string $path, bool $deep): iterable
        {
            return $this->delegatedAdapter->listContents($path, $deep);
        }

        public function move(string $source, string $destination, Config $config): void
        {
            $this->delegatedAdapter->move($source, $destination, $config);
        }

        public function copy(string $source, string $destination, Config $config): void
        {
            $this->delegatedAdapter->copy($source, $destination, $config);
        }
    }

    class FakeAzureOssContainerClient implements AzureBlockBlobContainerClient
    {
        /**
         * @var string
         */
        private $storageRoot;

        /**
         * @var array<string, FakeAzureOssBlockBlobClient>
         */
        private $blockBlobClients = [];

        public function __construct(string $storageRoot)
        {
            $this->storageRoot = $storageRoot;
        }

        public function getBlockBlobClient(string $blobPath): AzureBlockBlobClient
        {
            if (!isset($this->blockBlobClients[$blobPath])) {
                $this->blockBlobClients[$blobPath] = new FakeAzureOssBlockBlobClient($this->storageRoot, $blobPath);
            }

            return $this->blockBlobClients[$blobPath];
        }

        public function getExistingBlockBlobClient(string $blobPath): ?FakeAzureOssBlockBlobClient
        {
            return $this->blockBlobClients[$blobPath] ?? null;
        }
    }

    class FakeAzureOssBlockBlobClient implements AzureBlockBlobClient
    {
        /**
         * @var string
         */
        private $storageRoot;

        /**
         * @var string
         */
        private $blobPath;

        /**
         * @var array<string, string>
         */
        public $stagedBlocks = [];

        /**
         * @var array<int, string[]>
         */
        public $committedBlockLists = [];

        public function __construct(string $storageRoot, string $blobPath)
        {
            $this->storageRoot = $storageRoot;
            $this->blobPath = $blobPath;
        }

        public function stageBlock(string $blockId, string $content): void
        {
            $this->stagedBlocks[$blockId] = $content;
        }

        /**
         * @param string[] $blockIds
         */
        public function commitBlockList(array $blockIds): void
        {
            $this->committedBlockLists[] = $blockIds;

            $mergedContent = '';
            foreach ($blockIds as $blockId) {
                $mergedContent .= $this->stagedBlocks[$blockId];
            }

            $absoluteBlobPath = $this->storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->blobPath);
            $blobDirectory = dirname($absoluteBlobPath);

            if (!is_dir($blobDirectory) && !mkdir($blobDirectory, 0777, true) && !is_dir($blobDirectory)) {
                throw new \RuntimeException('Could not create the fake AzureOss blob directory.');
            }

            file_put_contents($absoluteBlobPath, $mergedContent);
        }
    }
}
