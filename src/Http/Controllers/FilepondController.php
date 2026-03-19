<?php

namespace Sopamo\LaravelFilepond\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
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
            return $this->handleChunkInitialization($request);
        }

        $file = is_array($input) ? $input[0] : $input;
        $path = config('filepond.temporary_files_path', 'filepond');
        $disk = config('filepond.temporary_files_disk', 'local');

        if (!($newFile = $file->storeAs($path.DIRECTORY_SEPARATOR.Str::random(), $file->getClientOriginalName(), $disk))) {
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
        $disk = config('filepond.temporary_files_disk', 'local');
        $storage = Storage::disk($disk);
        $fileLocation = $this->buildChunkInitializationFileLocation($request);

        if (!$storage->put($fileLocation, '')) {
            abort(500, 'Could not create file');
        }

        return Response::make($this->filepond->getServerIdFromPath($fileLocation), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Store a chunk using the multi-file approach
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     * @throws FileNotFoundException
     */
    public function multiFileChunk(Request $request)
    {
        $chunkUploadDetails = $this->getChunkUploadDetails($request);

        return $this->storeMultiFileChunk($request->getContent(), $chunkUploadDetails);
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
        $uploadedChunkSize = 0;
        $chunkPaths = $storage->files($basePath);

        foreach ($chunkPaths as $chunkPath) {
            $uploadedChunkSize += $storage->size($chunkPath);
        }

        if ($uploadedChunkSize < $length) {
            return;
        }

        usort($chunkPaths, function (string $leftChunkPath, string $rightChunkPath): int {
            return $this->extractChunkOffset($leftChunkPath) <=> $this->extractChunkOffset($rightChunkPath);
        });

        $temporaryMergedFile = tmpfile();
        if ($temporaryMergedFile === false) {
            throw new \RuntimeException('Could not create a temporary file for chunk merging.');
        }

        foreach ($chunkPaths as $chunkPath) {
            $chunkStream = $storage->readStream($chunkPath);
            stream_copy_to_stream($chunkStream, $temporaryMergedFile);

            if (is_resource($chunkStream)) {
                fclose($chunkStream);
            }
        }

        rewind($temporaryMergedFile);
        $storage->put($finalFilePath, $temporaryMergedFile);
        fclose($temporaryMergedFile);

        $storage->deleteDirectory($basePath);
    }

    /**
     * Handle a single chunk
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function chunk(Request $request)
    {
        $chunkUploadDetails = $this->getChunkUploadDetails($request);
        $storage = Storage::disk(config('filepond.temporary_files_disk', 'local'));
        $azureOssStorageContext = $this->resolveAzureOssStorageContext($storage);

        if ($azureOssStorageContext === null) {
            return $this->storeMultiFileChunk($request->getContent(), $chunkUploadDetails);
        }

        $this->storeAzureOssChunk($storage, $chunkUploadDetails, $request->getContent(), $azureOssStorageContext);

        return Response::make('', 204);
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
        $storage = Storage::disk(config('filepond.temporary_files_disk', 'local'));

        $temporaryDirectoryDeleted = $storage->deleteDirectory(dirname($filePath));
        $chunkDirectoryDeleted = $this->deleteDirectoryIfItExists($storage, $this->getChunkStoragePath($filePath));

        if ($temporaryDirectoryDeleted && $chunkDirectoryDeleted) {
            return Response::make('', 200, [
                'Content-Type' => 'text/plain',
            ]);
        }

        return Response::make('', 500, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * @param string $content
     * @param array{
     *     final_file_path: string,
     *     offset: int,
     *     length: int
     * } $chunkUploadDetails
     * @return \Illuminate\Http\Response
     * @throws FileNotFoundException
     */
    private function storeMultiFileChunk(string $content, array $chunkUploadDetails)
    {
        $disk = config('filepond.temporary_files_disk', 'local');
        $basePath = $this->getChunkStoragePath($chunkUploadDetails['final_file_path']);

        Storage::disk($disk)->put(
            $basePath.DIRECTORY_SEPARATOR.'patch.'.$chunkUploadDetails['offset'],
            $content,
            ['mimetype' => 'application/octet-stream']
        );

        $this->persistFileIfDone($disk, $basePath, $chunkUploadDetails['length'], $chunkUploadDetails['final_file_path']);

        return Response::make('', 204);
    }

    /**
     * @param mixed $storage
     * @param array{
     *     final_file_path: string,
     *     offset: int,
     *     length: int
     * } $chunkUploadDetails
     * @param string $content
     * @param array{
     *     container_client: object,
     *     path_prefixer: object
     * } $azureOssStorageContext
     */
    private function storeAzureOssChunk($storage, array $chunkUploadDetails, string $content, array $azureOssStorageContext): void
    {
        $blockBlobClient = $azureOssStorageContext['container_client']->getBlockBlobClient(
            $azureOssStorageContext['path_prefixer']->prefixPath($chunkUploadDetails['final_file_path'])
        );

        if (!is_object($blockBlobClient) || !method_exists($blockBlobClient, 'stageBlock') || !method_exists($blockBlobClient, 'commitBlockList')) {
            throw new \RuntimeException('Could not resolve the AzureOss block blob upload methods.');
        }

        $blockId = $this->buildAzureOssBlockId($chunkUploadDetails['offset']);
        $blockBlobClient->stageBlock($blockId, $content);

        $manifestPath = $this->getAzureOssManifestPath($chunkUploadDetails['final_file_path']);
        $manifest = $this->loadAzureOssManifest($storage, $manifestPath);
        $manifest['upload_length'] = $chunkUploadDetails['length'];
        $manifest['chunks'][(string) $chunkUploadDetails['offset']] = [
            'offset' => $chunkUploadDetails['offset'],
            'size' => strlen($content),
            'block_id' => $blockId,
        ];

        $manifestJson = json_encode($manifest);
        if ($manifestJson === false) {
            throw new \RuntimeException('Could not encode the AzureOss chunk upload manifest.');
        }

        $storage->put($manifestPath, $manifestJson);

        if ($this->uploadedAzureOssChunkBytes($manifest) < $manifest['upload_length']) {
            return;
        }

        $blockBlobClient->commitBlockList($this->orderedAzureOssBlockIds($manifest));
        $storage->deleteDirectory($this->getChunkStoragePath($chunkUploadDetails['final_file_path']));
    }

    /**
     * @param Request $request
     * @return array{
     *     final_file_path: string,
     *     offset: int,
     *     length: int
     * }
     */
    private function getChunkUploadDetails(Request $request): array
    {
        $encryptedPath = $request->input('patch');
        if (!$encryptedPath) {
            abort(400, 'No id given');
        }

        try {
            $finalFilePath = $this->filepond->getPathFromServerId($encryptedPath);
        } catch (DecryptException $exception) {
            abort(400, 'Invalid encryption for id');
        } catch (InvalidPathException $exception) {
            abort(400, 'Invalid encryption for id');
        }

        $offset = $request->server('HTTP_UPLOAD_OFFSET');
        $length = $request->server('HTTP_UPLOAD_LENGTH');

        if (!is_numeric($offset) || !is_numeric($length)) {
            abort(400, 'Invalid chunk length or offset');
        }

        return [
            'final_file_path' => $finalFilePath,
            'offset' => (int) $offset,
            'length' => (int) $length,
        ];
    }

    private function buildChunkInitializationFileLocation(Request $request): string
    {
        $temporaryFilesPath = config('filepond.temporary_files_path', 'filepond');
        $uploadDirectory = Str::random();
        $uploadName = trim((string) $request->header('Upload-Name'));

        if ($uploadName === '') {
            return $temporaryFilesPath.DIRECTORY_SEPARATOR.$uploadDirectory.DIRECTORY_SEPARATOR.$uploadDirectory;
        }

        return $temporaryFilesPath.DIRECTORY_SEPARATOR.$uploadDirectory.DIRECTORY_SEPARATOR.basename($uploadName);
    }

    private function getChunkStoragePath(string $finalFilePath): string
    {
        return config('filepond.chunks_path').DIRECTORY_SEPARATOR.sha1($finalFilePath);
    }

    private function getAzureOssManifestPath(string $finalFilePath): string
    {
        return $this->getChunkStoragePath($finalFilePath).DIRECTORY_SEPARATOR.'manifest.json';
    }

    private function extractChunkOffset(string $chunkPath): int
    {
        return (int) substr($chunkPath, strrpos($chunkPath, '.') + 1);
    }

    private function buildAzureOssBlockId(int $offset): string
    {
        return base64_encode(str_pad((string) $offset, 20, '0', STR_PAD_LEFT));
    }

    /**
     * @param mixed $storage
     * @return array{
     *     container_client: object,
     *     path_prefixer: object
     * }|null
     */
    private function resolveAzureOssStorageContext($storage): ?array
    {
        $storageAdapter = $this->extractStorageAdapter($storage);
        if (!is_object($storageAdapter)) {
            return null;
        }

        $innerAdapter = $this->readObjectProperty($storageAdapter, 'innerAdapter');
        if (is_object($innerAdapter)) {
            $storageAdapter = $innerAdapter;
        }

        if (!$this->isAzureOssAdapter($storageAdapter)) {
            return null;
        }

        $containerClient = $this->readObjectProperty($storageAdapter, 'containerClient');
        if (!is_object($containerClient) || !method_exists($containerClient, 'getBlockBlobClient')) {
            throw new \RuntimeException('Could not resolve the AzureOss block blob client.');
        }

        $pathPrefixer = $this->readObjectProperty($storageAdapter, 'prefixer');
        if (!is_object($pathPrefixer) || !method_exists($pathPrefixer, 'prefixPath')) {
            throw new \RuntimeException('Could not resolve the AzureOss path prefixer.');
        }

        return [
            'container_client' => $containerClient,
            'path_prefixer' => $pathPrefixer,
        ];
    }

    /**
     * @param mixed $storage
     * @return mixed
     */
    private function extractStorageAdapter($storage)
    {
        if (!is_object($storage)) {
            return null;
        }

        if (method_exists($storage, 'getAdapter')) {
            $adapter = $storage->getAdapter();
            if (is_object($adapter)) {
                return $adapter;
            }
        }

        if (!method_exists($storage, 'getDriver')) {
            return null;
        }

        $driver = $storage->getDriver();
        if (!is_object($driver) || !method_exists($driver, 'getAdapter')) {
            return null;
        }

        $adapter = $driver->getAdapter();

        return is_object($adapter) ? $adapter : null;
    }

    /**
     * @param mixed $adapter
     */
    private function isAzureOssAdapter($adapter): bool
    {
        if (!is_object($adapter)) {
            return false;
        }

        return is_a($adapter, 'AzureOss\\Storage\\BlobFlysystem\\AzureBlobStorageAdapter')
            || is_a($adapter, 'AzureOss\\FlysystemAzureBlobStorage\\AzureBlobStorageAdapter');
    }

    /**
     * @param mixed $storage
     * @return array{
     *     upload_length: int,
     *     chunks: array<string, array{offset: int, size: int, block_id: string}>
     * }
     */
    private function loadAzureOssManifest($storage, string $manifestPath): array
    {
        if (!$storage->exists($manifestPath)) {
            return [
                'upload_length' => 0,
                'chunks' => [],
            ];
        }

        $manifest = json_decode($storage->get($manifestPath), true);
        if (!is_array($manifest) || !isset($manifest['upload_length']) || !isset($manifest['chunks']) || !is_array($manifest['chunks'])) {
            throw new \RuntimeException('Invalid AzureOss chunk upload manifest.');
        }

        return $manifest;
    }

    /**
     * @param array{
     *     upload_length: int,
     *     chunks: array<string, array{offset: int, size: int, block_id: string}>
     * } $manifest
     * @return string[]
     */
    private function orderedAzureOssBlockIds(array $manifest): array
    {
        $chunkEntries = array_values($manifest['chunks']);

        usort($chunkEntries, function (array $leftChunk, array $rightChunk): int {
            return $leftChunk['offset'] <=> $rightChunk['offset'];
        });

        return array_map(function (array $chunkEntry): string {
            return $chunkEntry['block_id'];
        }, $chunkEntries);
    }

    /**
     * @param array{
     *     upload_length: int,
     *     chunks: array<string, array{offset: int, size: int, block_id: string}>
     * } $manifest
     */
    private function uploadedAzureOssChunkBytes(array $manifest): int
    {
        $uploadedChunkBytes = 0;

        foreach ($manifest['chunks'] as $chunkEntry) {
            $uploadedChunkBytes += $chunkEntry['size'];
        }

        return $uploadedChunkBytes;
    }

    /**
     * @param mixed $storage
     */
    private function deleteDirectoryIfItExists($storage, string $directoryPath): bool
    {
        if (method_exists($storage, 'directoryExists') && !$storage->directoryExists($directoryPath)) {
            return true;
        }

        if (!method_exists($storage, 'directoryExists') && method_exists($storage, 'exists') && !$storage->exists($directoryPath)) {
            return true;
        }

        return $storage->deleteDirectory($directoryPath);
    }

    /**
     * @param mixed $object
     * @return mixed
     */
    private function readObjectProperty($object, string $propertyName)
    {
        if (!is_object($object)) {
            return null;
        }

        try {
            $reflectionClass = new \ReflectionObject($object);

            while ($reflectionClass !== false) {
                if ($reflectionClass->hasProperty($propertyName)) {
                    $property = $reflectionClass->getProperty($propertyName);
                    $property->setAccessible(true);

                    return $property->getValue($object);
                }

                $reflectionClass = $reflectionClass->getParentClass();
            }
        } catch (\ReflectionException $exception) {
            return null;
        }

        return null;
    }
}
