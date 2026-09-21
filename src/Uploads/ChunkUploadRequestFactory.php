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
        $serverId = $request->input('patch');
        $offsetHeader = $request->header('Upload-Offset');
        $lengthHeader = $request->header('Upload-Length');
        $path = $this->serverIdPathResolver->resolvePath($serverId);
        $offset = $this->integerHeader($offsetHeader);
        $length = $this->integerHeader($lengthHeader);

        return new ChunkUploadRequest($path, $offset, $length);
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
