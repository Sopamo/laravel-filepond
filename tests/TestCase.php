<?php

namespace Sopamo\LaravelFilepond\Tests;

use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Sopamo\LaravelFilepond\LaravelFilepondServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    public function setUp(): void
    {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'FakeAzureClasses.php';

        parent::setUp();

        $this->registerTestFilesystemDrivers();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelFilepondServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // perform environment setup
    }

    protected function registerTestFilesystemDrivers(): void
    {
        $testCase = $this;

        Storage::extend('fake-azure-oss', function ($app, array $config) use ($testCase) {
            $baseRoot = $config['test_root'];
            $prefix = isset($config['prefix']) ? $config['prefix'] : (isset($config['root']) ? $config['root'] : '');
            $localRoot = $testCase->makePrefixedRoot($baseRoot, $prefix);
            $container = isset($config['container']) ? $config['container'] : 'test-container';
            $containerClient = new \AzureOss\Storage\Blob\BlobContainerClient($baseRoot, $container);
            $adapter = new \AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter($localRoot, $containerClient);

            return new LaravelFilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });

        Storage::extend('fake-legacy-azure', function ($app, array $config) use ($testCase) {
            $baseRoot = $config['test_root'];
            $prefix = isset($config['prefix']) ? $config['prefix'] : '';
            $localRoot = $testCase->makePrefixedRoot($baseRoot, $prefix);
            $container = isset($config['container']) ? $config['container'] : 'test-container';
            $client = new \MicrosoftAzure\Storage\Blob\BlobRestProxy($baseRoot);
            $adapter = new \Matthewbdaly\LaravelAzureStorage\AzureBlobStorageAdapter($localRoot, $client, $container, $prefix);

            return new LaravelFilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }

    protected function makeDiskRoot($name): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel-filepond-tests' . DIRECTORY_SEPARATOR . $name . '-' . uniqid('', true);
        $this->ensureDirectory($path);

        return $path;
    }

    protected function initChunkUpload($fileName = 'chunked.txt', array $server = [])
    {
        return $this->call('POST', '/filepond/api/process', [], [], [], array_merge([
            'HTTP_UPLOAD_NAME' => $fileName,
            'CONTENT_TYPE' => 'text/plain',
        ], $server));
    }

    protected function patchChunk($serverId, $content, $offset, $length, array $server = [])
    {
        return $this->call('PATCH', '/filepond/api', [
            'patch' => $serverId,
        ], [], [], array_merge([
            'HTTP_UPLOAD_OFFSET' => (string) $offset,
            'HTTP_UPLOAD_LENGTH' => (string) $length,
            'CONTENT_TYPE' => 'application/offset+octet-stream',
        ], $server), $content);
    }

    protected function deleteUpload($serverId, array $server = [])
    {
        return $this->call('DELETE', '/filepond/api/process', [], [], [], array_merge([
            'CONTENT_TYPE' => 'text/plain',
        ], $server), $serverId);
    }

    protected function makePrefixedRoot($baseRoot, $prefix): string
    {
        $normalizedPrefix = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $prefix), DIRECTORY_SEPARATOR);
        $path = rtrim($baseRoot, DIRECTORY_SEPARATOR);

        if ($normalizedPrefix !== '') {
            $path .= DIRECTORY_SEPARATOR . $normalizedPrefix;
        }

        $this->ensureDirectory($path);

        return $path;
    }

    protected function ensureDirectory($path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}
