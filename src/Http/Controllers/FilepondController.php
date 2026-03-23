<?php

namespace Sopamo\LaravelFilepond\Http\Controllers;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sopamo\LaravelFilepond\Exceptions\InvalidPathException;
use Sopamo\LaravelFilepond\Filepond;

class FilepondController extends BaseController
{
    /**
     * Maximum size for Azure append block operations (4MB)
     */
    private const MAX_APPEND_BLOCK_SIZE = 4 * 1024 * 1024;

    /**
     * @var string
     */
    private const STRATEGY_GENERIC_MULTI_FILE = 'generic_multi_file';

    /**
     * @var string
     */
    private const STRATEGY_LEGACY_APPEND_BLOB = 'legacy_append_blob';

    /**
     * @var string
     */
    private const STRATEGY_AZURE_OSS_BLOCK_BLOB = 'azure_oss_block_blob';

    /**
     * @var string
     */
    private const CHUNK_SESSION_FILE = 'upload.json';

    /**
     * @var string
     */
    private const LEGACY_AZURE_ADAPTER_CLASS = 'Matthewbdaly\\LaravelAzureStorage\\AzureBlobStorageAdapter';

    /**
     * @var string
     */
    private const LEGACY_AZURE_CLIENT_CLASS = 'MicrosoftAzure\\Storage\\Blob\\BlobRestProxy';

    /**
     * @var string
     */
    private const AZURE_OSS_ADAPTER_CLASS = 'AzureOss\\Storage\\BlobFlysystem\\AzureBlobStorageAdapter';

    /**
     * @var string
     */
    private const AZURE_OSS_CONTAINER_CLIENT_CLASS = 'AzureOss\\Storage\\Blob\\BlobContainerClient';

    /**
     * @var string
     */
    private const AZURE_OSS_BLOB_HTTP_HEADERS_CLASS = 'AzureOss\\Storage\\Blob\\Models\\BlobHttpHeaders';

    /**
     * @var string
     */
    private const AZURE_OSS_COMMIT_BLOCK_LIST_OPTIONS_CLASS = 'AzureOss\\Storage\\Blob\\Models\\CommitBlockListOptions';

    /**
     * @var Filepond
     */
    private $filepond;

    public function __construct(Filepond $filepond)
    {
        $this->filepond = $filepond;
    }

    /**
     * Uploads the file to the temporary directory
     * and returns an encrypted path to the file
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function upload(Request $request)
    {
        $input = $request->file(config('filepond.input_name'));

        if ($input === null) {
            // This is a chunk initialization request
            return $this->handleChunkInitialization($request);
        }

        $file = is_array($input) ? $input[0] : $input;
        $path = config('filepond.temporary_files_path', 'filepond');
        $disk = config('filepond.temporary_files_disk', 'local');

        if (!($newFile = $file->storeAs($path . DIRECTORY_SEPARATOR . Str::random(), $file->getClientOriginalName(), $disk))) {
            return Response::make('Could not save file', 500, [
                'Content-Type' => 'text/plain',
            ]);
        }

        return Response::make($this->filepond->getServerIdFromPath($newFile), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * This handles the case where filepond wants to start uploading chunks of a file
     * See: https://pqina.nl/filepond/docs/patterns/api/server/
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    private function handleChunkInitialization(Request $request)
    {
        $randomId = Str::random();
        $path = config('filepond.temporary_files_path', 'filepond');
        $disk = config('filepond.temporary_files_disk', 'local');
        $storage = Storage::disk($disk);

        $baseName = $randomId;
        if ($request->header('Upload-Name')) {
            $fileName = pathinfo($request->header('Upload-Name'), PATHINFO_FILENAME);
            $ext = pathinfo($request->header('Upload-Name'), PATHINFO_EXTENSION);
            $baseName = $fileName . '-' . $randomId . '.' . $ext;
        }

        $fileLocation = $path . DIRECTORY_SEPARATOR . $baseName;
        $uploadId = $this->getChunkUploadId($fileLocation);
        $context = $this->resolveChunkUploadContext($storage);
        $strategy = self::STRATEGY_GENERIC_MULTI_FILE;
        $fileCreated = false;

        if ($context['strategy'] === self::STRATEGY_LEGACY_APPEND_BLOB) {
            $blobLocation = $this->buildBlobPath($context['blob_path_prefix'], $fileLocation);
            $fileCreated = $this->createAzureAppendBlob($context['client'], $context['container'], $blobLocation, $request);

            if ($fileCreated) {
                $strategy = self::STRATEGY_LEGACY_APPEND_BLOB;
            }
        }

        if (!$fileCreated) {
            $fileCreated = $storage->put($fileLocation, '');

            if ($fileCreated && $context['strategy'] === self::STRATEGY_AZURE_OSS_BLOCK_BLOB) {
                $strategy = self::STRATEGY_AZURE_OSS_BLOCK_BLOB;
            }
        }

        if (!$fileCreated) {
            abort(500, 'Could not create file');
        }

        $sessionStored = $this->persistChunkSession($disk, $uploadId, [
            'strategy' => $strategy,
            'final_path' => $fileLocation,
            'content_type' => $request->header('Content-Type', 'application/octet-stream'),
        ]);

        if (!$sessionStored) {
            $this->cleanupChunkUpload($storage, $fileLocation, $uploadId);
            abort(500, 'Could not create upload session');
        }

        $filepondId = $this->filepond->getServerIdFromPath($fileLocation);

        return Response::make($filepondId, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Store a chunk using the multi-file approach (and not Azure's append blob)
     *
     * @param Request $request
     * @param string|null $finalFilePath
     * @param string|null $uploadId
     * @return \Illuminate\Http\Response
     * @throws FileNotFoundException
     */
    public function multiFileChunk(Request $request, $finalFilePath = null, $uploadId = null)
    {
        if ($finalFilePath === null) {
            $finalFilePath = $this->getFilePathFromPatch($request);
        }

        if ($uploadId === null) {
            $uploadId = $this->getChunkUploadId($finalFilePath);
        }

        $disk = config('filepond.temporary_files_disk', 'local');
        $basePath = $this->getChunkBasePath($uploadId);
        list($offset, $length) = $this->getChunkPatchInfo($request);

        Storage::disk($disk)->put(
            $basePath . DIRECTORY_SEPARATOR . 'patch.' . $offset,
            $request->getContent(),
            ['mimetype' => 'application/octet-stream']
        );

        $this->persistFileIfDone($disk, $basePath, $length, $finalFilePath);

        return Response::make('', 204);
    }

    /**
     * This checks if all chunks have been uploaded and if they have, it creates the final file
     *
     * @param string $disk
     * @param string $basePath
     * @param int $length
     * @param string $finalFilePath
     * @throws FileNotFoundException
     */
    private function persistFileIfDone($disk, $basePath, $length, $finalFilePath)
    {
        $storage = Storage::disk($disk);
        $chunkFiles = $this->getMultiFileChunkFiles($storage, $basePath);

        if (empty($chunkFiles)) {
            return;
        }

        $sortedChunks = [];
        $totalSize = 0;
        $expectedOffset = 0;

        foreach ($chunkFiles as $chunk) {
            $offset = (int) substr($chunk, strrpos($chunk, '.') + 1);
            $sortedChunks[$offset] = $chunk;
        }

        ksort($sortedChunks, SORT_NUMERIC);

        foreach ($sortedChunks as $offset => $chunk) {
            $chunkSize = (int) $storage->size($chunk);

            if ($offset !== $expectedOffset) {
                abort(400, 'Invalid chunk offsets');
            }

            $totalSize += $chunkSize;
            $expectedOffset += $chunkSize;
        }

        if ($totalSize > $length) {
            abort(400, 'Invalid chunk length or offset');
        }

        if ($totalSize < $length) {
            return;
        }

        $tmpFile = tmpfile();
        $tmpFileName = stream_get_meta_data($tmpFile)['uri'];

        foreach ($sortedChunks as $chunk) {
            $chunkContents = $storage->readStream($chunk);
            stream_copy_to_stream($chunkContents, $tmpFile);

            if (is_resource($chunkContents)) {
                fclose($chunkContents);
            }
        }

        rewind($tmpFile);
        $storage->put($finalFilePath, $tmpFile);
        $storage->deleteDirectory($basePath);

        if (is_resource($tmpFile)) {
            fclose($tmpFile);
        }

        if (file_exists($tmpFileName)) {
            unlink($tmpFileName);
        }
    }

    /**
     * Handle a single chunk upload.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function chunk(Request $request)
    {
        $disk = config('filepond.temporary_files_disk', 'local');
        $storage = Storage::disk($disk);
        $finalFilePath = $this->getFilePathFromPatch($request);
        $uploadId = $this->getChunkUploadId($finalFilePath);
        $session = $this->loadChunkSession($disk, $uploadId);

        if (is_array($session) && isset($session['strategy'])) {
            if ($session['strategy'] === self::STRATEGY_AZURE_OSS_BLOCK_BLOB) {
                return $this->azureOssChunk($request, $storage, $disk, $finalFilePath, $uploadId, $session);
            }

            if ($session['strategy'] === self::STRATEGY_LEGACY_APPEND_BLOB) {
                return $this->legacyAzureChunk($request, $storage, $finalFilePath, $uploadId, false);
            }

            return $this->multiFileChunk($request, $finalFilePath, $uploadId);
        }

        $context = $this->resolveChunkUploadContext($storage);

        if ($context['strategy'] === self::STRATEGY_LEGACY_APPEND_BLOB) {
            return $this->legacyAzureChunk($request, $storage, $finalFilePath, $uploadId, true);
        }

        return $this->multiFileChunk($request, $finalFilePath, $uploadId);
    }

    /**
     * Takes the given encrypted filepath and deletes
     * it if it hasn't been tampered with
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function delete(Request $request)
    {
        $filePath = $this->filepond->getPathFromServerId($request->getContent());
        $disk = config('filepond.temporary_files_disk', 'local');
        $storage = Storage::disk($disk);
        $uploadId = $this->getChunkUploadId($filePath);
        $chunkBasePath = $this->getChunkBasePath($uploadId);
        $temporaryFilesPath = $this->normalizePath(config('filepond.temporary_files_path', 'filepond'));
        $folderPath = dirname($filePath);
        $isChunkedUpload = $this->loadChunkSession($disk, $uploadId) !== null
            || $this->normalizePath($folderPath) === $temporaryFilesPath;

        if ($isChunkedUpload) {
            $deletedFile = $this->deleteFileIfExists($storage, $filePath);
            $deletedChunks = $this->deleteDirectoryIfExists($storage, $chunkBasePath);

            if ($deletedFile && $deletedChunks) {
                return Response::make('', 200, [
                    'Content-Type' => 'text/plain',
                ]);
            }

            return Response::make('', 500, [
                'Content-Type' => 'text/plain',
            ]);
        }

        if ($storage->deleteDirectory($folderPath)) {
            return Response::make('', 200, [
                'Content-Type' => 'text/plain',
            ]);
        }

        return Response::make('', 500, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * @param mixed $storage
     * @return array<string, mixed>
     */
    private function resolveChunkUploadContext($storage)
    {
        $storageAdapter = $this->getStorageAdapter($storage);

        if (!is_object($storageAdapter)) {
            return [
                'strategy' => self::STRATEGY_GENERIC_MULTI_FILE,
            ];
        }

        $legacyAzureAdapter = $this->findStorageAdapterByClass($storageAdapter, self::LEGACY_AZURE_ADAPTER_CLASS);

        if ($legacyAzureAdapter !== null) {
            list($client, $container) = $this->getLegacyAzureClientAndContainer($legacyAzureAdapter);

            if (is_object($client) && class_exists(self::LEGACY_AZURE_CLIENT_CLASS) && is_a($client, self::LEGACY_AZURE_CLIENT_CLASS) && is_string($container) && trim($container) !== '') {
                return [
                    'strategy' => self::STRATEGY_LEGACY_APPEND_BLOB,
                    'client' => $client,
                    'container' => $container,
                    'blob_path_prefix' => $this->resolveLegacyAzureBlobPrefix($storage, $legacyAzureAdapter),
                ];
            }
        }

        $azureOssAdapter = $this->findStorageAdapterByClass($storageAdapter, self::AZURE_OSS_ADAPTER_CLASS);

        if ($azureOssAdapter !== null) {
            $containerClient = $this->getObjectProperty($azureOssAdapter, 'containerClient');

            if (is_object($containerClient) && class_exists(self::AZURE_OSS_CONTAINER_CLIENT_CLASS) && is_a($containerClient, self::AZURE_OSS_CONTAINER_CLIENT_CLASS)) {
                return [
                    'strategy' => self::STRATEGY_AZURE_OSS_BLOCK_BLOB,
                    'container_client' => $containerClient,
                    'blob_path_prefix' => $this->resolveAzureOssBlobPrefix($storage),
                ];
            }
        }

        return [
            'strategy' => self::STRATEGY_GENERIC_MULTI_FILE,
        ];
    }

    /**
     * @param mixed $storage
     * @return mixed
     */
    private function getStorageAdapter($storage)
    {
        try {
            if (method_exists($storage, 'getAdapter')) {
                return $storage->getAdapter();
            }
        } catch (\Exception $e) {
            // Fall through to driver reflection.
        }

        try {
            if (method_exists($storage, 'getDriver')) {
                $driver = $storage->getDriver();

                if (is_object($driver) && method_exists($driver, 'getAdapter')) {
                    return $driver->getAdapter();
                }

                if (is_object($driver)) {
                    return $this->getObjectProperty($driver, 'adapter');
                }
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * @param mixed $object
     * @param string $property
     * @return mixed
     */
    private function getObjectProperty($object, $property)
    {
        if (!is_object($object)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($object);

            while ($reflection instanceof \ReflectionClass) {
                if ($reflection->hasProperty($property)) {
                    $reflectionProperty = $reflection->getProperty($property);
                    $reflectionProperty->setAccessible(true);

                    return $reflectionProperty->getValue($object);
                }

                $reflection = $reflection->getParentClass();
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * @param mixed $storage
     * @param mixed $adapter
     * @return string
     */
    private function resolveLegacyAzureBlobPrefix($storage, $adapter)
    {
        $config = $this->getStorageConfig($storage);

        if (isset($config['prefix']) && is_string($config['prefix'])) {
            return $config['prefix'];
        }

        $prefix = $this->getObjectProperty($adapter, 'prefix');

        if (is_string($prefix)) {
            return $prefix;
        }

        return '';
    }

    /**
     * @param mixed $storage
     * @return string
     */
    private function resolveAzureOssBlobPrefix($storage)
    {
        $config = $this->getStorageConfig($storage);

        if (isset($config['prefix']) && is_string($config['prefix']) && trim($config['prefix']) !== '') {
            return $config['prefix'];
        }

        if (isset($config['root']) && is_string($config['root']) && trim($config['root']) !== '') {
            return $config['root'];
        }

        return '';
    }

    /**
     * @param mixed $storage
     * @return array
     */
    private function getStorageConfig($storage)
    {
        try {
            if (method_exists($storage, 'getConfig')) {
                return (array) $storage->getConfig();
            }
        } catch (\Exception $e) {
            return [];
        }

        return (array) config('filesystems.disks.' . config('filepond.temporary_files_disk', 'local'), []);
    }

    /**
     * @param mixed $adapter
     * @return array
     */
    private function getLegacyAzureClientAndContainer($adapter)
    {
        $client = $this->getObjectProperty($adapter, 'client');
        $container = $this->getObjectProperty($adapter, 'container');

        if (!is_string($container)) {
            $container = null;
        }

        return [$client, $container];
    }

    /**
     * Create an Azure append blob
     *
     * @param mixed $client
     * @param string $container
     * @param string $blobLocation
     * @param Request $request
     * @return bool
     */
    private function createAzureAppendBlob($client, $container, $blobLocation, Request $request)
    {
        try {
            $options = new \MicrosoftAzure\Storage\Blob\Models\CreateBlobOptions();
            $options->setContentType($request->header('Content-Type', 'application/octet-stream'));

            $client->createAppendBlob(
                $container,
                $blobLocation,
                $options
            );

            return true;
        } catch (\Exception $e) {
            logger()->error('Error creating Azure append blob: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param Request $request
     * @param mixed $storage
     * @param string $finalFilePath
     * @param string $uploadId
     * @param bool $allowFallback
     * @return \Illuminate\Http\Response
     * @throws FileNotFoundException
     */
    private function legacyAzureChunk(Request $request, $storage, $finalFilePath, $uploadId, $allowFallback)
    {
        $context = $this->resolveChunkUploadContext($storage);

        if ($context['strategy'] !== self::STRATEGY_LEGACY_APPEND_BLOB) {
            if ($allowFallback) {
                return $this->multiFileChunk($request, $finalFilePath, $uploadId);
            }

            abort(500, 'Could not continue the chunk upload');
        }

        $this->getChunkPatchInfo($request);

        try {
            $content = $request->getContent();
            $contentLength = strlen($content);
            $blobLocation = $this->buildBlobPath($context['blob_path_prefix'], $finalFilePath);
            $appendBlockOptions = new \MicrosoftAzure\Storage\Blob\Models\AppendBlockOptions();

            if ($contentLength > self::MAX_APPEND_BLOCK_SIZE) {
                $position = 0;

                while ($position < $contentLength) {
                    $chunk = substr($content, $position, self::MAX_APPEND_BLOCK_SIZE);
                    $context['client']->appendBlock(
                        $context['container'],
                        $blobLocation,
                        $chunk,
                        $appendBlockOptions
                    );

                    $position += self::MAX_APPEND_BLOCK_SIZE;
                }
            } else {
                $context['client']->appendBlock(
                    $context['container'],
                    $blobLocation,
                    $content,
                    $appendBlockOptions
                );
            }

            return Response::make('', 204);
        } catch (\Exception $e) {
            if ($allowFallback) {
                return $this->multiFileChunk($request, $finalFilePath, $uploadId);
            }

            abort(500, 'Could not append chunk');
        }
    }

    /**
     * @param Request $request
     * @param mixed $storage
     * @param string $disk
     * @param string $finalFilePath
     * @param string $uploadId
     * @param array<string, mixed> $session
     * @return \Illuminate\Http\Response
     */
    private function azureOssChunk(Request $request, $storage, $disk, $finalFilePath, $uploadId, array $session)
    {
        $context = $this->resolveChunkUploadContext($storage);

        if ($context['strategy'] !== self::STRATEGY_AZURE_OSS_BLOCK_BLOB) {
            abort(500, 'Could not continue the chunk upload');
        }

        list($offset, $length) = $this->getChunkPatchInfo($request);
        $content = $request->getContent();
        $blockId = $this->getAzureOssBlockId($offset);
        $blobLocation = $this->buildBlobPath($context['blob_path_prefix'], $finalFilePath);
        $blockBlobClient = $context['container_client']->getBlockBlobClient($blobLocation);

        try {
            $blockBlobClient->stageBlock($blockId, $content);
        } catch (\Exception $e) {
            abort(500, 'Could not stage chunk');
        }

        $uncommittedBlocks = $this->getAzureOssUncommittedBlocks($blockBlobClient);
        $blockIds = $this->getAzureOssCommitBlockIds($uncommittedBlocks, $length);

        if ($blockIds === null) {
            return Response::make('', 204);
        }

        $commitOptions = $this->makeAzureOssCommitBlockListOptions(
            isset($session['content_type']) && is_string($session['content_type']) ? $session['content_type'] : 'application/octet-stream'
        );

        if ($commitOptions === null) {
            abort(500, 'Could not prepare block blob commit');
        }

        try {
            $blockBlobClient->commitBlockList($blockIds, $commitOptions);
            $this->deleteDirectoryIfExists($storage, $this->getChunkBasePath($uploadId));
        } catch (\Exception $e) {
            abort(500, 'Could not commit block blob');
        }

        return Response::make('', 204);
    }

    /**
     * @param string $contentType
     * @return mixed
     */
    private function makeAzureOssCommitBlockListOptions($contentType)
    {
        if (!class_exists(self::AZURE_OSS_BLOB_HTTP_HEADERS_CLASS) || !class_exists(self::AZURE_OSS_COMMIT_BLOCK_LIST_OPTIONS_CLASS)) {
            return null;
        }

        $headersClass = self::AZURE_OSS_BLOB_HTTP_HEADERS_CLASS;
        $optionsClass = self::AZURE_OSS_COMMIT_BLOCK_LIST_OPTIONS_CLASS;
        $headers = new $headersClass();
        $headers->contentType = $contentType;

        return new $optionsClass($headers);
    }

    /**
     * @param mixed $blockBlobClient
     * @return array<int, array<string, mixed>>
     */
    private function getAzureOssUncommittedBlocks($blockBlobClient)
    {
        $client = $this->getObjectProperty($blockBlobClient, 'client');
        $uri = $this->getObjectProperty($blockBlobClient, 'uri');

        if (!is_object($client) || $uri === null || !method_exists($client, 'get')) {
            abort(500, 'Could not inspect block blob upload state');
        }

        try {
            $response = $client->get($uri, [
                \GuzzleHttp\RequestOptions::QUERY => [
                    'comp' => 'blocklist',
                    'blocklisttype' => 'uncommitted',
                ],
            ]);
            $body = $response->getBody()->getContents();
        } catch (\Exception $e) {
            abort(500, 'Could not inspect block blob upload state');
        }

        $xml = @simplexml_load_string($body);

        if (!($xml instanceof \SimpleXMLElement)) {
            abort(500, 'Could not inspect block blob upload state');
        }

        $blocks = [];

        if (!isset($xml->UncommittedBlocks)) {
            return $blocks;
        }

        foreach ($xml->UncommittedBlocks->Block as $block) {
            if (!isset($block->Name, $block->Size)) {
                continue;
            }

            $blockId = (string) $block->Name;
            $offset = $this->getAzureOssOffsetFromBlockId($blockId);
            $blocks[$offset] = [
                'offset' => $offset,
                'size' => (int) $block->Size,
                'block_id' => $blockId,
            ];
        }

        ksort($blocks, SORT_NUMERIC);

        return $blocks;
    }

    /**
     * @param array<int, array<string, mixed>> $markers
     * @param int $length
     * @return array<int, string>|null
     */
    private function getAzureOssCommitBlockIds(array $markers, $length)
    {
        $expectedOffset = 0;
        $totalSize = 0;
        $blockIds = [];

        foreach ($markers as $offset => $marker) {
            $size = (int) $marker['size'];

            if ($offset !== $expectedOffset) {
                abort(400, 'Invalid chunk offsets');
            }

            $totalSize += $size;
            $expectedOffset += $size;
            $blockIds[] = (string) $marker['block_id'];
        }

        if ($totalSize > $length) {
            abort(400, 'Invalid chunk length or offset');
        }

        if ($totalSize < $length) {
            return null;
        }

        return $blockIds;
    }

    /**
     * @param Request $request
     * @return array<int, int>
     */
    private function getChunkPatchInfo(Request $request)
    {
        $offset = $request->server('HTTP_UPLOAD_OFFSET');
        $length = $request->server('HTTP_UPLOAD_LENGTH');

        if (!is_numeric($offset) || !is_numeric($length)) {
            abort(400, 'Invalid chunk length or offset');
        }

        return [(int) $offset, (int) $length];
    }

    /**
     * @param Request $request
     * @return string
     */
    private function getFilePathFromPatch(Request $request)
    {
        $encryptedPath = $request->input('patch');

        if (!$encryptedPath) {
            abort(400, 'No id given');
        }

        try {
            return $this->filepond->getPathFromServerId($encryptedPath);
        } catch (InvalidPathException $e) {
            abort(400, 'Invalid encryption for id');
        }
    }

    /**
     * @param string $filePath
     * @return string
     */
    private function getChunkUploadId($filePath)
    {
        return basename($filePath);
    }

    /**
     * @param string $uploadId
     * @return string
     */
    private function getChunkBasePath($uploadId)
    {
        return config('filepond.chunks_path', 'filepond' . DIRECTORY_SEPARATOR . 'chunks') . DIRECTORY_SEPARATOR . $uploadId;
    }

    /**
     * @param string $uploadId
     * @return string
     */
    private function getChunkSessionPath($uploadId)
    {
        return $this->getChunkBasePath($uploadId) . DIRECTORY_SEPARATOR . self::CHUNK_SESSION_FILE;
    }

    /**
     * @param string $disk
     * @param string $uploadId
     * @param array<string, mixed> $session
     * @return bool
     */
    private function persistChunkSession($disk, $uploadId, array $session)
    {
        $contents = json_encode($session);

        if ($contents === false) {
            return false;
        }

        return Storage::disk($disk)->put($this->getChunkSessionPath($uploadId), $contents) !== false;
    }

    /**
     * @param string $disk
     * @param string $uploadId
     * @return array<string, mixed>|null
     */
    private function loadChunkSession($disk, $uploadId)
    {
        $storage = Storage::disk($disk);
        $sessionPath = $this->getChunkSessionPath($uploadId);

        if (!$this->storagePathExists($storage, $sessionPath)) {
            return null;
        }

        $contents = $storage->get($sessionPath);
        $session = json_decode($contents, true);

        return is_array($session) ? $session : null;
    }

    /**
     * @param mixed $storage
     * @param string $basePath
     * @return array<int, string>
     */
    private function getMultiFileChunkFiles($storage, $basePath)
    {
        $chunks = [];

        if (!$this->storagePathExists($storage, $basePath)) {
            return $chunks;
        }

        foreach ($storage->files($basePath) as $file) {
            if (Str::startsWith(basename($file), 'patch.')) {
                $chunks[] = $file;
            }
        }

        return $chunks;
    }

    /**
     * @param mixed $storage
     * @param string $filePath
     * @param string $uploadId
     * @return void
     */
    private function cleanupChunkUpload($storage, $filePath, $uploadId)
    {
        $this->deleteFileIfExists($storage, $filePath);
        $this->deleteDirectoryIfExists($storage, $this->getChunkBasePath($uploadId));
    }

    /**
     * @param mixed $storage
     * @param string $path
     * @return bool
     */
    private function deleteFileIfExists($storage, $path)
    {
        if (!$this->storagePathExists($storage, $path)) {
            return true;
        }

        return $storage->delete($path);
    }

    /**
     * @param mixed $storage
     * @param string $path
     * @return bool
     */
    private function deleteDirectoryIfExists($storage, $path)
    {
        if (!$this->storagePathExists($storage, $path)) {
            return true;
        }

        return $storage->deleteDirectory($path);
    }

    /**
     * @param mixed $storage
     * @param string $path
     * @return bool
     */
    private function storagePathExists($storage, $path)
    {
        try {
            return $storage->exists($path);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param string $prefix
     * @param string $path
     * @return string
     */
    private function buildBlobPath($prefix, $path)
    {
        $normalizedPrefix = trim($this->normalizePath($prefix), '/');
        $normalizedPath = trim($this->normalizePath($path), '/');

        if ($normalizedPrefix === '') {
            return $normalizedPath;
        }

        return $normalizedPrefix . '/' . $normalizedPath;
    }

    /**
     * @param string $path
     * @return string
     */
    private function normalizePath($path)
    {
        return str_replace('\\', '/', (string) $path);
    }

    /**
     * @param int $offset
     * @return string
     */
    private function getAzureOssBlockId($offset)
    {
        return base64_encode(str_pad((string) $offset, 20, '0', STR_PAD_LEFT));
    }

    /**
     * @param string $blockId
     * @return int
     */
    private function getAzureOssOffsetFromBlockId($blockId)
    {
        $decodedBlockId = base64_decode($blockId, true);

        if ($decodedBlockId === false || !ctype_digit($decodedBlockId)) {
            abort(500, 'Invalid block blob upload state');
        }

        return (int) ltrim($decodedBlockId, '0');
    }

    /**
     * @param mixed $adapter
     * @param string $className
     * @return object|null
     */
    private function findStorageAdapterByClass($adapter, $className)
    {
        if (!class_exists($className) || !is_object($adapter)) {
            return null;
        }

        return $this->findObjectByClass($adapter, $className, []);
    }

    /**
     * @param mixed $value
     * @param string $className
     * @param array<int, true> $visitedObjectIds
     * @return object|null
     */
    private function findObjectByClass($value, $className, array $visitedObjectIds)
    {
        if (!is_object($value)) {
            return null;
        }

        if (is_a($value, $className)) {
            return $value;
        }

        $objectId = spl_object_hash($value);
        if (isset($visitedObjectIds[$objectId])) {
            return null;
        }

        $visitedObjectIds[$objectId] = true;

        try {
            $reflection = new \ReflectionClass($value);

            while ($reflection instanceof \ReflectionClass) {
                foreach ($reflection->getProperties() as $reflectionProperty) {
                    $reflectionProperty->setAccessible(true);
                    $propertyValue = $reflectionProperty->getValue($value);

                    if (!is_object($propertyValue)) {
                        continue;
                    }

                    $matchingObject = $this->findObjectByClass($propertyValue, $className, $visitedObjectIds);
                    if ($matchingObject !== null) {
                        return $matchingObject;
                    }
                }

                $reflection = $reflection->getParentClass();
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }
}
