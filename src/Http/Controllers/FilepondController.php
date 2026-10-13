<?php

namespace Sopamo\LaravelFilepond\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Config;
use Sopamo\LaravelFilepond\Exceptions\IncompleteUploadException;
use Sopamo\LaravelFilepond\Exceptions\InvalidUploadRequestException;
use Sopamo\LaravelFilepond\Uploads\ChunkUploadRequestFactory;
use Sopamo\LaravelFilepond\Uploads\ChunkUploadService;
use Sopamo\LaravelFilepond\Uploads\TemporaryUploadService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FilepondController extends BaseController
{
    public function __construct(
        private readonly TemporaryUploadService $temporaryUploadService,
        private readonly ChunkUploadRequestFactory $chunkUploadRequestFactory,
        private readonly ChunkUploadService $chunkUploadService
    ) {
    }

    /**
     * Uploads the file to the temporary directory
     * and returns an encrypted path to the file
     */
    public function upload(Request $request): Response
    {
        $input = $request->file(Config::string('filepond.input_name'));

        if ($input === null) {
            $serverId = $this->temporaryUploadService->initializeChunkUpload(
                $request->header('Upload-Name'),
                $request->headers->get('Content-Type')
            );

            return $this->plainTextResponse($serverId, 200);
        }

        // FilePond also supports array-style inputs such as file[].
        $file = is_array($input) ? reset($input) : $input;
        if (!$file instanceof UploadedFile) {
            return $this->plainTextResponse('Could not save file', 500);
        }

        $serverId = $this->temporaryUploadService->storeUploadedFile($file);
        if ($serverId === null) {
            return $this->plainTextResponse('Could not save file', 500);
        }

        return $this->plainTextResponse($serverId, 200);
    }

    public function chunk(Request $request): Response
    {
        try {
            $chunk = $this->chunkUploadRequestFactory->fromRequest($request);
            $this->chunkUploadService->store($chunk, $request->getContent());
        } catch (InvalidUploadRequestException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        } catch (IncompleteUploadException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        return $this->plainTextResponse('', 204);
    }

    /**
     * Takes the given encrypted filepath and deletes
     * it if it hasn't been tampered with
     */
    public function delete(Request $request): Response
    {
        try {
            $deleted = $this->temporaryUploadService->deleteByServerId($request->getContent());
        } catch (InvalidUploadRequestException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        return $this->plainTextResponse('', $deleted ? 200 : 500);
    }

    private function plainTextResponse(string $content, int $status): Response
    {
        return new Response($content, $status, [
            'Content-Type' => 'text/plain',
        ]);
    }
}
