<?php

namespace Sopamo\LaravelFilepond\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Sopamo\LaravelFilepond\Exceptions\LaravelFilepondException;
use Sopamo\LaravelFilepond\Filepond;
use Sopamo\LaravelFilepond\Http\Requests\PatchUploadRequest;
use Sopamo\LaravelFilepond\Http\Requests\ProcessUploadRequest;
use Sopamo\LaravelFilepond\Http\Requests\RevertUploadRequest;

class FilepondController extends Controller
{
    public function __construct(private readonly Filepond $filepond)
    {
    }

    public function upload(ProcessUploadRequest $request): Response
    {
        return $this->respond(function () use ($request): Response {
            $file = $request->uploadedFile();
            $serverId = $file === null
                ? $this->filepond->initializeChunkUpload($request->uploadName(), $request->uploadLength())
                : $this->filepond->store($file);

            return response($serverId, 200, ['Content-Type' => 'text/plain']);
        });
    }

    public function chunk(PatchUploadRequest $request): Response
    {
        return $this->respond(function () use ($request): Response {
            $offset = $this->filepond->storeChunk(
                $request->serverId(),
                $request->uploadOffset(),
                $request->uploadLength(),
                $request->getContent(),
            );

            return response('', 204, ['Upload-Offset' => (string) $offset]);
        });
    }

    public function delete(RevertUploadRequest $request): Response
    {
        return $this->respond(function () use ($request): Response {
            $this->filepond->revert($request->serverId());

            return response('', 200, ['Content-Type' => 'text/plain']);
        });
    }

    private function respond(callable $callback): Response
    {
        try {
            return $callback();
        } catch (LaravelFilepondException $exception) {
            return response($exception->getMessage(), $exception->status(), ['Content-Type' => 'text/plain']);
        }
    }
}
