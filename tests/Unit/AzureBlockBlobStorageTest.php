<?php

namespace Sopamo\LaravelFilepond\Tests\Unit;

use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Sopamo\LaravelFilepond\Tests\TestCase;
use Sopamo\LaravelFilepond\Uploads\AzureBlockBlobStorage;

class AzureBlockBlobStorageTest extends TestCase
{
    public function test_real_adapter_and_application_wrapper_preserve_the_same_prefix(): void
    {
        $azure = new AzureBlobStorageAdapter(new BlobContainerClient(new Uri('https://example.com/container')), 'prefix');
        $wrapped = new class($this->createTemporaryDirectory('wrapped'), $azure) extends LocalFilesystemAdapter {
            public function __construct(string $root, private readonly AzureBlobStorageAdapter $innerAdapter)
            {
                parent::__construct($root);
            }
        };
        foreach ([$azure, $wrapped] as $adapter) {
            $storage = new FilesystemAdapter(new Filesystem($adapter), $adapter);
            $bridge = AzureBlockBlobStorage::fromFilesystem($storage);
            $this->assertNotNull($bridge);
            $this->assertSame('/container/prefix/filepond/Safety+Association%20(1).pdf',
                $bridge->client('filepond/Safety+Association (1).pdf')->uri->getPath());
        }
    }

    public function test_non_azure_disks_use_the_generic_upload_path(): void
    {
        $this->assertNull(AzureBlockBlobStorage::fromFilesystem(Storage::fake('local')));
    }
}
