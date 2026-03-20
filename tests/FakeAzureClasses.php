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

        public function __construct($root, $blobName)
        {
            $this->path = $this->buildPath($root, $blobName);
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

        private function buildPath($root, $blobName)
        {
            $normalizedBlob = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $blobName), DIRECTORY_SEPARATOR);

            return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedBlob;
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
