<?php

namespace Sopamo\LaravelFilepond\Tests;

use Sopamo\LaravelFilepond\LaravelFilepondServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * @var string[]
     */
    private $temporaryDirectories = [];

    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $temporaryDirectory) {
            $this->deleteDirectoryRecursively($temporaryDirectory);
        }

        parent::tearDown();
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

    protected function createTemporaryDirectory($directoryName): string
    {
        $temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$directoryName.'-'.uniqid('', true);

        if (!mkdir($temporaryDirectory, 0777, true) && !is_dir($temporaryDirectory)) {
            throw new \RuntimeException('Could not create temporary directory for tests.');
        }

        $this->temporaryDirectories[] = $temporaryDirectory;

        return $temporaryDirectory;
    }

    private function deleteDirectoryRecursively($directoryPath): void
    {
        if (!is_dir($directoryPath)) {
            return;
        }

        $directoryItems = scandir($directoryPath);
        if ($directoryItems === false) {
            return;
        }

        foreach ($directoryItems as $directoryItem) {
            if ($directoryItem === '.' || $directoryItem === '..') {
                continue;
            }

            $itemPath = $directoryPath.DIRECTORY_SEPARATOR.$directoryItem;
            if (is_dir($itemPath) && !is_link($itemPath)) {
                $this->deleteDirectoryRecursively($itemPath);
                continue;
            }

            unlink($itemPath);
        }

        rmdir($directoryPath);
    }
}
