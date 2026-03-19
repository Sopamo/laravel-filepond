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
use Sopamo\LaravelFilepond\Support\AzureOssChunkUploadManifest;
use Sopamo\LaravelFilepond\Support\ChunkUploadAdapterResolver;

class FilepondController extends BaseController
{
    /**
     * Maximum size for Azure append block operations (4MB)
     */
    private const MAX_APPEND_BLOCK_SIZE = 4 * 1024 * 1024;

    /**
     * @var Filepond
     */
    private $filepond;

    /**
     * @var ChunkUploadAdapterResolver
     */
    private $chunkUploadAdapterResolver;

    public function __construct(Filepond $filepond, ChunkUploadAdapterResolver $chunkUploadAdapterResolver)
    {
        $this->filepond = $filepond;
        $this->chunkUploadAdapterResolver = $chunkUploadAdapterResolver;
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
     * Create an Azure append blob
     *
     * @param \MicrosoftAzure\Storage\Blob\BlobRestProxy $client
     * @param string $container
     * @param string $fileLocation
     * @param Request $request
     * @return bool
     */
    private function createAzureAppendBlob($client, string $container, string $fileLocation, Request $request): bool
    {
        try {
            $options = new \MicrosoftAzure\Storage\Blob\Models\CreateBlobOptions();
            $options->setContentType($request->header('Content-Type', 'application/octet-stream'));

            $client->createAppendBlob(
                $container,
                $fileLocation,
                $options
            );

            return true;
        } catch (\Exception $exception) {
            logger()->error('Error creating Azure append blob: '.$exception->getMessage());

            return false;
        }
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
        $resolvedStorageAdapter = $this->chunkUploadAdapterResolver->resolve($storage);

        $fileCreated = false;

        if ($resolvedStorageAdapter['type'] === ChunkUploadAdapterResolver::TYPE_LEGACY_AZURE) {
            $client = $resolvedStorageAdapter['client'];
            $container = $resolvedStorageAdapter['container'];

            if ($client instanceof \MicrosoftAzure\Storage\Blob\BlobRestProxy && !empty($container)) {
                $fileCreated = $this->createAzureAppendBlob($client, $container, $fileLocation, $request);
            }
        }

        if (!$fileCreated) {
            $fileCreated = $storage->put($fileLocation, '');
        }

        if (!$fileCreated) {
            abort(500, 'Could not create file');
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
     * @return \Illuminate\Http\Response
     * @throws FileNotFoundException
     */
    public function multiFileChunk(Request $request)
    {
        $chunkUploadDetails = $this->getChunkUploadDetails($request);

        $disk = config('filepond.temporary_files_disk', 'local');
        $basePath = $this->getChunkStoragePath($chunkUploadDetails['final_file_path']);

        Storage::disk($disk)->put(
            $basePath.DIRECTORY_SEPARATOR.'patch.'.$chunkUploadDetails['offset'],
            $request->getContent(),
            ['mimetype' => 'application/octet-stream']
        );

        $this->persistFileIfDone($disk, $basePath, $chunkUploadDetails['length'], $chunkUploadDetails['final_file_path']);

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
        $disk = config('filepond.temporary_files_disk', 'local');
        $storage = Storage::disk($disk);
        $resolvedStorageAdapter = $this->chunkUploadAdapterResolver->resolve($storage);

        if ($resolvedStorageAdapter['type'] === ChunkUploadAdapterResolver::TYPE_AZURE_OSS) {
            $this->storeAzureOssChunk($storage, $chunkUploadDetails, $request->getContent(), $resolvedStorageAdapter);

            return Response::make('', 204);
        }

        if ($resolvedStorageAdapter['type'] === ChunkUploadAdapterResolver::TYPE_LEGACY_AZURE) {
            return $this->appendLegacyAzureChunk($request, $chunkUploadDetails, $resolvedStorageAdapter);
        }

        return $this->multiFileChunk($request);
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
        $chunkDirectoryDeleted = $storage->deleteDirectory($this->getChunkStoragePath($filePath));

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
     * @param mixed $storage
     * @param array{
     *     final_file_path: string,
     *     offset: int,
     *     length: int
     * } $chunkUploadDetails
     * @param string $content
     * @param array<string, mixed> $resolvedStorageAdapter
     */
    private function storeAzureOssChunk($storage, array $chunkUploadDetails, string $content, array $resolvedStorageAdapter): void
    {
        $containerClient = $resolvedStorageAdapter['container_client'];
        if (!is_object($containerClient) || !method_exists($containerClient, 'getBlockBlobClient')) {
            throw new \RuntimeException('Could not resolve the AzureOss block blob client.');
        }

        $pathPrefixer = $resolvedStorageAdapter['path_prefixer'];
        if (!is_object($pathPrefixer) || !method_exists($pathPrefixer, 'prefixPath')) {
            throw new \RuntimeException('Could not resolve the AzureOss path prefixer.');
        }

        $blockBlobClient = $containerClient->getBlockBlobClient(
            $pathPrefixer->prefixPath($chunkUploadDetails['final_file_path'])
        );

        if (!is_object($blockBlobClient) || !method_exists($blockBlobClient, 'stageBlock') || !method_exists($blockBlobClient, 'commitBlockList')) {
            throw new \RuntimeException('Could not resolve the AzureOss block blob upload methods.');
        }

        $blockId = $this->buildAzureOssBlockId($chunkUploadDetails['offset']);
        $blockBlobClient->stageBlock($blockId, $content);

        $manifest = new AzureOssChunkUploadManifest(
            $storage,
            $this->getAzureOssManifestPath($chunkUploadDetails['final_file_path'])
        );

        $manifest->recordChunk(
            $chunkUploadDetails['offset'],
            strlen($content),
            $blockId,
            $chunkUploadDetails['length']
        );

        if (!$manifest->hasReceivedAllBytes()) {
            return;
        }

        $blockBlobClient->commitBlockList($manifest->orderedBlockIds());
        $manifest->delete();
    }

    /**
     * @param Request $request
     * @param array{
     *     final_file_path: string,
     *     offset: int,
     *     length: int
     * } $chunkUploadDetails
     * @param array<string, mixed> $resolvedStorageAdapter
     * @return \Illuminate\Http\Response
     */
    private function appendLegacyAzureChunk(Request $request, array $chunkUploadDetails, array $resolvedStorageAdapter)
    {
        $client = $resolvedStorageAdapter['client'];
        $container = $resolvedStorageAdapter['container'];

        if (!$client instanceof \MicrosoftAzure\Storage\Blob\BlobRestProxy || empty($container)) {
            return $this->multiFileChunk($request);
        }

        try {
            $content = $request->getContent();
            $contentLength = strlen($content);
            $appendBlockOptions = new \MicrosoftAzure\Storage\Blob\Models\AppendBlockOptions();

            if ($contentLength > self::MAX_APPEND_BLOCK_SIZE) {
                $position = 0;

                while ($position < $contentLength) {
                    $client->appendBlock(
                        $container,
                        $chunkUploadDetails['final_file_path'],
                        substr($content, $position, self::MAX_APPEND_BLOCK_SIZE),
                        $appendBlockOptions
                    );
                    $position += self::MAX_APPEND_BLOCK_SIZE;
                }
            } else {
                $client->appendBlock(
                    $container,
                    $chunkUploadDetails['final_file_path'],
                    $content,
                    $appendBlockOptions
                );
            }

            return Response::make('', 204);
        } catch (\Exception $exception) {
            return $this->multiFileChunk($request);
        }
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
}
