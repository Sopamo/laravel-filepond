<?php

namespace AzureOss\FlysystemAzureBlobStorage {
    class AzureBlobStorageAdapter
    {
        public function __construct(
            private readonly mixed $containerClient,
            private readonly mixed $prefixer
        ) {
        }
    }
}

namespace Sopamo\LaravelFilepond\Tests\Unit {
    use AzureOss\FlysystemAzureBlobStorage\AzureBlobStorageAdapter;
    use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
    use League\Flysystem\Config;
    use League\Flysystem\FileAttributes;
    use League\Flysystem\Filesystem;
    use League\Flysystem\FilesystemAdapter;
    use League\Flysystem\Local\LocalFilesystemAdapter;
    use Sopamo\LaravelFilepond\Tests\TestCase;
    use Sopamo\LaravelFilepond\Uploads\AzureBlockBlobClient;
    use Sopamo\LaravelFilepond\Uploads\AzureBlockBlobContainerClient;
    use Sopamo\LaravelFilepond\Uploads\AzureBlockBlobChunkWriteHandlerFactory;
    use Sopamo\LaravelFilepond\Uploads\AzureBlockBlobChunkWriteHandler;
    use Sopamo\LaravelFilepond\Uploads\AzurePathPrefixer;
    use Sopamo\LaravelFilepond\Uploads\ChunkWriteHandlerResolver;
    use Sopamo\LaravelFilepond\Uploads\FilepondConfiguration;
    use Sopamo\LaravelFilepond\Uploads\FilesystemChunkWriteHandler;
    use Sopamo\LaravelFilepond\Uploads\UploadPathResolver;

    class AzureBlockBlobChunkWriteHandlerFactoryTest extends TestCase
    {
        /** @test */
        public function test_it_builds_a_chunk_write_handler_from_a_wrapped_azure_adapter()
        {
            $storage = $this->makeAzureStorage('laravel-filepond-azure-bridge');
            $factory = new AzureBlockBlobChunkWriteHandlerFactory(
                new UploadPathResolver(new FilepondConfiguration())
            );
            $handler = $factory->create($storage);

            $this->assertInstanceOf(AzureBlockBlobChunkWriteHandler::class, $handler);
        }

        /** @test */
        public function test_it_returns_null_for_non_azure_storage()
        {
            $storageRoot = $this->createTemporaryDirectory('laravel-filepond-non-azure');
            $adapter = new LocalFilesystemAdapter($storageRoot);
            $storage = new LaravelFilesystemAdapter(new Filesystem($adapter), $adapter, [
                'root' => $storageRoot,
            ]);

            $factory = new AzureBlockBlobChunkWriteHandlerFactory(
                new UploadPathResolver(new FilepondConfiguration())
            );

            $this->assertNull($factory->create($storage));
        }

        private function makeAzureStorage(string $directoryName): LaravelFilesystemAdapter
        {
            $storageRoot = $this->createTemporaryDirectory($directoryName);
            $localAdapter = new LocalFilesystemAdapter($storageRoot);
            $wrappedAdapter = new WrappedAzureAdapterStub(
                $localAdapter,
                new AzureBlobStorageAdapter(new BridgeFactoryContainerClientStub(), new PrefixerStub('prefix'))
            );

            return new LaravelFilesystemAdapter(new Filesystem($localAdapter), $wrappedAdapter, [
                'root' => $storageRoot,
            ]);
        }
    }

    class ChunkWriteHandlerResolverTest extends TestCase
    {
        /** @test */
        public function test_it_returns_the_azure_handler_for_azure_storage()
        {
            $resolver = new ChunkWriteHandlerResolver(
                new UploadPathResolver(new FilepondConfiguration()),
                new AzureBlockBlobChunkWriteHandlerFactory(
                    new UploadPathResolver(new FilepondConfiguration())
                )
            );

            $this->assertInstanceOf(
                AzureBlockBlobChunkWriteHandler::class,
                $resolver->resolve($this->makeAzureStorage('laravel-filepond-azure-handler'))
            );
        }

        /** @test */
        public function test_it_returns_the_filesystem_handler_for_non_azure_storage()
        {
            $storageRoot = $this->createTemporaryDirectory('laravel-filepond-filesystem-handler');
            $adapter = new LocalFilesystemAdapter($storageRoot);
            $storage = new LaravelFilesystemAdapter(new Filesystem($adapter), $adapter, [
                'root' => $storageRoot,
            ]);
            $resolver = new ChunkWriteHandlerResolver(
                new UploadPathResolver(new FilepondConfiguration()),
                new AzureBlockBlobChunkWriteHandlerFactory(
                    new UploadPathResolver(new FilepondConfiguration())
                )
            );

            $this->assertInstanceOf(FilesystemChunkWriteHandler::class, $resolver->resolve($storage));
        }

        private function makeAzureStorage(string $directoryName): LaravelFilesystemAdapter
        {
            $storageRoot = $this->createTemporaryDirectory($directoryName);
            $localAdapter = new LocalFilesystemAdapter($storageRoot);
            $wrappedAdapter = new WrappedAzureAdapterStub(
                $localAdapter,
                new AzureBlobStorageAdapter(new ResolverContainerClientStub(), new PrefixerStub('prefix'))
            );

            return new LaravelFilesystemAdapter(new Filesystem($localAdapter), $wrappedAdapter, [
                'root' => $storageRoot,
            ]);
        }
    }

    class WrappedAzureAdapterStub implements FilesystemAdapter
    {
        public function __construct(
            private readonly FilesystemAdapter $delegatedAdapter,
            private readonly mixed $innerAdapter
        ) {
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

    class BridgeFactoryContainerClientStub implements AzureBlockBlobContainerClient
    {
        public function getBlockBlobClient(string $path): AzureBlockBlobClient
        {
            return new BlockBlobClientStub();
        }
    }

    class ResolverContainerClientStub implements AzureBlockBlobContainerClient
    {
        public function getBlockBlobClient(string $path): AzureBlockBlobClient
        {
            return new BlockBlobClientStub();
        }
    }

    class PrefixerStub implements AzurePathPrefixer
    {
        public function __construct(private readonly string $prefix)
        {
        }

        public function prefixPath(string $path): string
        {
            return $this->prefix.'/'.$path;
        }
    }

    class BlockBlobClientStub implements AzureBlockBlobClient
    {
        public function stageBlock(string $blockId, string $content): void
        {
        }

        public function commitBlockList(array $blockIds): void
        {
        }
    }
}
