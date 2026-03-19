<?php

namespace Sopamo\LaravelFilepond\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Response;
use Sopamo\LaravelFilepond\Exceptions\InvalidUploadRequestException;
use Sopamo\LaravelFilepond\Uploads\ChunkUploadRequest;
use Sopamo\LaravelFilepond\Uploads\ChunkUploadRequestFactory;
use Sopamo\LaravelFilepond\Uploads\ChunkUploadService;
use Sopamo\LaravelFilepond\Uploads\FilepondConfiguration;
use Sopamo\LaravelFilepond\Uploads\TemporaryUploadService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class FilepondController extends BaseController
{
    public function __construct(
        private readonly FilepondConfiguration $configuration,
        private readonly TemporaryUploadService $temporaryUploadService,
        private readonly ChunkUploadRequestFactory $chunkUploadRequestFactory,
        private readonly ChunkUploadService $chunkUploadService
    ) {
    }

    /**
     * Uploads the file to the temporary directory
     * and returns an encrypted path to the file
     *
     * @return \Illuminate\Http\Response
     */
    public function upload(Request $request): \Illuminate\Http\Response
    {
        $input = $request->file($this->configuration->inputName());

        if ($input === null) {
            return $this->handleChunkInitialization($request);
        }

        $file = $this->resolveUploadedFile($input);
        if ($file === null) {
            return $this->plainTextResponse('Could not save file', 500);
        }

        $serverId = $this->temporaryUploadService->storeUploadedFile($file);
        if ($serverId === null) {
            return $this->plainTextResponse('Could not save file', 500);
        }

        return $this->plainTextResponse($serverId, 200);
    }

    public function chunk(Request $request): \Illuminate\Http\Response
    {
        $this->chunkUploadService->store(
            $this->createChunkUploadRequest($request),
            $request->getContent()
        );

        return $this->plainTextResponse('', 204);
    }

    /**
     * Takes the given encrypted filepath and deletes
     * it if it hasn't been tampered with
     *
     * @return \Illuminate\Http\Response
     */
    public function delete(Request $request): \Illuminate\Http\Response
    {
        try {
            $deleted = $this->temporaryUploadService->deleteByServerId($request->getContent());
        } catch (InvalidUploadRequestException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        return $this->plainTextResponse('', $deleted ? 200 : 500);
    }

    private function handleChunkInitialization(Request $request): \Illuminate\Http\Response
    {
        $serverId = $this->temporaryUploadService->initializeChunkUpload($request->header('Upload-Name'));

        return $this->plainTextResponse($serverId, 200);
    }

    private function createChunkUploadRequest(Request $request): ChunkUploadRequest
    {
        try {
            return $this->chunkUploadRequestFactory->fromRequest($request);
        } catch (InvalidUploadRequestException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }
    }

    private function resolveUploadedFile(mixed $input): ?UploadedFile
    {
        if (is_array($input)) {
            $input = reset($input);
        }

        return $input instanceof UploadedFile ? $input : null;
    }

    private function plainTextResponse(string $content, int $status): \Illuminate\Http\Response
    {
        return Response::make($content, $status, [
            'Content-Type' => 'text/plain',
        ]);
    }
}
