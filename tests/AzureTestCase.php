<?php

namespace Sopamo\LaravelFilepond\Tests;

use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

abstract class AzureTestCase extends TestCase
{
    protected string $azureRoot;
    protected FilesystemAdapter $storage;
    private $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->azureRoot = $this->createTemporaryDirectory('filepond-azure');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        $this->server = proc_open([PHP_BINARY, '-S', $address, __DIR__.'/Fixtures/azure-router.php'], [
            0 => ['pipe', 'r'],
            1 => ['file', $this->azureRoot.'/server.log', 'a'],
            2 => ['file', $this->azureRoot.'/server.log', 'a'],
        ], $pipes, null, ['FILEPOND_AZURE_TEST_ROOT' => $this->azureRoot]);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://'.$address, $error, $message, 0.05);
            if ($connection !== false) {
                fclose($connection);
                break;
            }
            usleep(10000);
        }
        $this->assertNotFalse($connection, 'The Azure HTTP fixture did not start.');

        $azure = new AzureBlobStorageAdapter(new BlobContainerClient(new Uri('http://'.$address.'/devstoreaccount1/container')), 'azure-prefix');
        $local = new LocalFilesystemAdapter($this->azureRoot.'/files/azure-prefix');
        $this->storage = new FilesystemAdapter(new Filesystem($local), $azure);
        Storage::set('azure-test', $this->storage);
        config(['filepond.temporary_files_disk' => 'azure-test']);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
    }

    protected function azureRequests(): array
    {
        return array_map(function ($line) {
            $request = json_decode($line, true);
            $request['body'] = base64_decode($request['body']);

            return $request;
        },
            file($this->azureRoot.'/requests.jsonl', FILE_IGNORE_NEW_LINES));
    }
}
