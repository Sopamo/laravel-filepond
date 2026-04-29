<?php

namespace Sopamo\LaravelFilepond\Tests\Unit;

use Sopamo\LaravelFilepond\Exceptions\InvalidPathException;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\Tests\TestCase;

class FilepondTest extends TestCase
{
    public function test_it_converts_paths_to_server_ids_and_back()
    {
        $filepond = app(Filepond::class);
        $path = 'filepond/uploads/test.txt';

        $serverId = $filepond->getServerIdFromPath($path);

        $this->assertNotSame($path, $serverId);
        $this->assertSame($path, $filepond->getPathFromServerId($serverId));
    }

    public function test_it_rejects_empty_server_ids()
    {
        $this->expectException(InvalidPathException::class);

        app(Filepond::class)->getPathFromServerId('');
    }

    public function test_it_rejects_paths_outside_the_temporary_directory()
    {
        $filepond = app(Filepond::class);
        $serverId = $filepond->getServerIdFromPath('other/path/test.txt');

        $this->expectException(InvalidPathException::class);

        $filepond->getPathFromServerId($serverId);
    }
}
