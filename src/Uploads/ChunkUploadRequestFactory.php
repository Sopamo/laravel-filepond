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
        $path = $this->serverIdPathResolver->resolvePath($request->input('patch'));
        $offset = $this->integerHeader($request->header('Upload-Offset'));
        $length = $this->integerHeader($request->header('Upload-Length'));

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

        // Reject numbers too large for a PHP int while allowing leading zeros.
        $number = (int) $normalizedValue;
        $digits = ltrim($normalizedValue, '0');
        if ((string) $number !== ($digits === '' ? '0' : $digits)) {
            throw new InvalidUploadRequestException('Invalid chunk length or offset');
        }

        return $number;
    }
}
