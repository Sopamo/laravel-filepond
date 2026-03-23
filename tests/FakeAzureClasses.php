<?php

namespace AzureOss\Storage\Blob;

if (!class_exists('AzureOss\\Storage\\Blob\\BlobContainerClient', false)) {
    class BlobContainerClient
    {
        /**
         * @var string
         */
        public $containerName;

        /**
         * @var string
         */
        private $root;

        public function __construct($root, $containerName = 'test-container')
        {
            $this->root = rtrim($root, DIRECTORY_SEPARATOR);
            $this->containerName = $containerName;
        }

        public function getBlobClient($blobName)
        {
            return new BlobClient($this->root, $blobName);
        }

        public function getBlockBlobClient($blobName)
        {
            return new \AzureOss\Storage\Blob\Specialized\BlockBlobClient($this->root, $blobName);
        }
    }
}

if (!class_exists('AzureOss\\Storage\\Blob\\BlobClient', false)) {
    class BlobClient
    {
        /**
         * @var string
         */
        private $path;

        public function __construct($root, $blobName)
        {
            $this->path = $this->buildPath($root, $blobName);
        }

        public function deleteIfExists()
        {
            if (file_exists($this->path)) {
                unlink($this->path);
            }
        }

        private function buildPath($root, $blobName)
        {
            $normalizedBlob = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $blobName), DIRECTORY_SEPARATOR);

            return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedBlob;
        }
    }
}

namespace AzureOss\Storage\Blob\Specialized;

if (!class_exists('AzureOss\\Storage\\Blob\\Specialized\\BlockBlobClient', false)) {
    class BlockBlobClient
    {
        /**
         * @var array<string, array<string, string>>
         */
        private static $stagedBlocks = [];

        /**
         * @var string
         */
        private $path;

        /**
         * @var mixed
         */
        private $client;

        /**
         * @var string
         */
        private $uri;

        public function __construct($root, $blobName)
        {
            $this->path = $this->buildPath($root, $blobName);
            $this->uri = $this->path;
            $this->client = new BlockBlobClientFakeHttpClient($this->path);
        }

        public function stageBlock($base64BlockId, $content, $options = null)
        {
            if (is_resource($content)) {
                $content = stream_get_contents($content);
            }

            self::$stagedBlocks[$this->path][$base64BlockId] = (string) $content;
        }

        public function commitBlockList(array $base64BlockIds, $options = null)
        {
            $contents = '';

            foreach ($base64BlockIds as $base64BlockId) {
                $contents .= isset(self::$stagedBlocks[$this->path][$base64BlockId])
                    ? self::$stagedBlocks[$this->path][$base64BlockId]
                    : '';
            }

            $directory = dirname($this->path);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($this->path, $contents);
            unset(self::$stagedBlocks[$this->path]);
        }

        /**
         * @param string $path
         * @return array<string, string>
         */
        public static function getStagedBlocksForPath($path)
        {
            if (!isset(self::$stagedBlocks[$path])) {
                return [];
            }

            return self::$stagedBlocks[$path];
        }

        private function buildPath($root, $blobName)
        {
            $normalizedBlob = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $blobName), DIRECTORY_SEPARATOR);

            return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedBlob;
        }
    }

    class BlockBlobClientFakeHttpClient
    {
        /**
         * @var string
         */
        private $path;

        public function __construct($path)
        {
            $this->path = $path;
        }

        public function get($uri, array $options = [])
        {
            $xml = new \SimpleXMLElement('<BlockList></BlockList>');
            $uncommittedBlocks = $xml->addChild('UncommittedBlocks');

            foreach (BlockBlobClient::getStagedBlocksForPath($this->path) as $blockId => $content) {
                $block = $uncommittedBlocks->addChild('Block');
                $block->addChild('Name', $blockId);
                $block->addChild('Size', (string) strlen($content));
            }

            return new BlockBlobClientFakeResponse($xml->asXML());
        }
    }

    class BlockBlobClientFakeResponse
    {
        /**
         * @var string
         */
        private $contents;

        public function __construct($contents)
        {
            $this->contents = $contents;
        }

        public function getBody()
        {
            return new BlockBlobClientFakeBody($this->contents);
        }
    }

    class BlockBlobClientFakeBody
    {
        /**
         * @var string
         */
        private $contents;

        public function __construct($contents)
        {
            $this->contents = $contents;
        }

        public function getContents()
        {
            return $this->contents;
        }
    }
}

namespace AzureOss\Storage\Blob\Models;

if (!class_exists('AzureOss\\Storage\\Blob\\Models\\BlobHttpHeaders', false)) {
    class BlobHttpHeaders
    {
        /**
         * @var string
         */
        public $contentType = '';
    }
}

if (!class_exists('AzureOss\\Storage\\Blob\\Models\\CommitBlockListOptions', false)) {
    class CommitBlockListOptions
    {
        /**
         * @var BlobHttpHeaders|null
         */
        public $httpHeaders;

        public function __construct($httpHeaders = null)
        {
            $this->httpHeaders = $httpHeaders;
        }
    }
}

namespace AzureOss\Storage\BlobFlysystem;

use AzureOss\Storage\Blob\BlobContainerClient;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

if (!class_exists('AzureOss\\Storage\\BlobFlysystem\\AzureBlobStorageAdapter', false)) {
    class AzureBlobStorageAdapter extends LocalFilesystemAdapter
    {
        /**
         * @var BlobContainerClient
         */
        private $containerClient;

        public function __construct($root, BlobContainerClient $containerClient)
        {
            parent::__construct($root);
            $this->containerClient = $containerClient;
        }
    }
}

if (!class_exists('AzureOss\\Storage\\BlobFlysystem\\WrappedAzureOssAdapter', false)) {
    class WrappedAzureOssAdapter implements FilesystemAdapter
    {
        /**
         * @var FilesystemAdapter
         */
        private $innerAdapter;

        public function __construct(FilesystemAdapter $innerAdapter)
        {
            $this->innerAdapter = $innerAdapter;
        }

        public function fileExists(string $path): bool
        {
            return $this->innerAdapter->fileExists($path);
        }

        public function directoryExists(string $path): bool
        {
            return $this->innerAdapter->directoryExists($path);
        }

        public function write(string $path, string $contents, Config $config): void
        {
            $this->innerAdapter->write($path, $contents, $config);
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            $this->innerAdapter->writeStream($path, $contents, $config);
        }

        public function read(string $path): string
        {
            return $this->innerAdapter->read($path);
        }

        public function readStream(string $path)
        {
            return $this->innerAdapter->readStream($path);
        }

        public function delete(string $path): void
        {
            $this->innerAdapter->delete($path);
        }

        public function deleteDirectory(string $path): void
        {
            $this->innerAdapter->deleteDirectory($path);
        }

        public function createDirectory(string $path, Config $config): void
        {
            $this->innerAdapter->createDirectory($path, $config);
        }

        public function setVisibility(string $path, string $visibility): void
        {
            $this->innerAdapter->setVisibility($path, $visibility);
        }

        public function visibility(string $path): FileAttributes
        {
            return $this->innerAdapter->visibility($path);
        }

        public function mimeType(string $path): FileAttributes
        {
            return $this->innerAdapter->mimeType($path);
        }

        public function lastModified(string $path): FileAttributes
        {
            return $this->innerAdapter->lastModified($path);
        }

        public function fileSize(string $path): FileAttributes
        {
            return $this->innerAdapter->fileSize($path);
        }

        public function listContents(string $path, bool $deep): iterable
        {
            return $this->innerAdapter->listContents($path, $deep);
        }

        public function move(string $source, string $destination, Config $config): void
        {
            $this->innerAdapter->move($source, $destination, $config);
        }

        public function copy(string $source, string $destination, Config $config): void
        {
            $this->innerAdapter->copy($source, $destination, $config);
        }
    }
}

namespace Matthewbdaly\LaravelAzureStorage;

use League\Flysystem\Local\LocalFilesystemAdapter;

if (!class_exists('Matthewbdaly\\LaravelAzureStorage\\AzureBlobStorageAdapter', false)) {
    class AzureBlobStorageAdapter extends LocalFilesystemAdapter
    {
        /**
         * @var mixed
         */
        private $client;

        /**
         * @var string
         */
        private $container;

        /**
         * @var string
         */
        private $prefix;

        public function __construct($root, $client, $container, $prefix = '')
        {
            parent::__construct($root);
            $this->client = $client;
            $this->container = $container;
            $this->prefix = $prefix;
        }
    }
}

namespace MicrosoftAzure\Storage\Blob;

if (!class_exists('MicrosoftAzure\\Storage\\Blob\\BlobRestProxy', false)) {
    class BlobRestProxy
    {
        /**
         * @var string
         */
        private $root;

        public function __construct($root)
        {
            $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        }

        public function createAppendBlob($container, $blobName, $options = null)
        {
            $path = $this->buildPath($blobName);
            $directory = dirname($path);

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($path, '');
        }

        public function appendBlock($container, $blobName, $content, $options = null)
        {
            $path = $this->buildPath($blobName);
            $directory = dirname($path);

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($path, (string) $content, FILE_APPEND);
        }

        private function buildPath($blobName)
        {
            $normalizedBlob = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $blobName), DIRECTORY_SEPARATOR);

            return $this->root . DIRECTORY_SEPARATOR . $normalizedBlob;
        }
    }
}

namespace MicrosoftAzure\Storage\Blob\Models;

if (!class_exists('MicrosoftAzure\\Storage\\Blob\\Models\\CreateBlobOptions', false)) {
    class CreateBlobOptions
    {
        /**
         * @var string
         */
        private $contentType = '';

        public function setContentType($contentType)
        {
            $this->contentType = (string) $contentType;
        }

        public function getContentType()
        {
            return $this->contentType;
        }
    }
}

if (!class_exists('MicrosoftAzure\\Storage\\Blob\\Models\\AppendBlockOptions', false)) {
    class AppendBlockOptions
    {
    }
}
