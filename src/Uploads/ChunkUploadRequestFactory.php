<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Http\Request;
use Sopamo\LaravelFilepond\Exceptions\InvalidUploadRequestException;

class ChunkUploadRequestFactory
{
    public function __construct(private readonly ServerIdPathResolver $serverIdPathResolver)
    {
    }

    /**
     * @throws InvalidUploadRequestException
     */
    public function fromRequest(Request $request): ChunkUploadRequest
    {
        return new ChunkUploadRequest(
            $this->serverIdPathResolver->resolvePath($request->input('patch')),
            $this->integerHeader($request->header('Upload-Offset')),
            $this->integerHeader($request->header('Upload-Length'))
        );
    }

    /**
     * @param array<int, string>|string|null $value
     * @throws InvalidUploadRequestException
     */
    private function integerHeader(array|string|null $value): int
    {
        if (is_array($value)) {
            throw new InvalidUploadRequestException('Invalid chunk length or offset');
        }

        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '' || preg_match('/^\d+$/', $normalizedValue) !== 1) {
            throw new InvalidUploadRequestException('Invalid chunk length or offset');
        }

        return (int) $normalizedValue;
    }
}
