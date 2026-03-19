<?php

namespace Sopamo\LaravelFilepond\Uploads;

use Illuminate\Contracts\Encryption\DecryptException;
use Sopamo\LaravelFilepond\Exceptions\InvalidPathException;
use Sopamo\LaravelFilepond\Exceptions\InvalidUploadRequestException;
use Sopamo\LaravelFilepond\ServerIdCodec;

class ServerIdPathResolver
{
    public function __construct(private readonly ServerIdCodec $serverIdCodec)
    {
    }

    /**
     * @throws InvalidUploadRequestException
     */
    public function resolvePath(mixed $serverId): string
    {
        if (is_array($serverId) || is_object($serverId)) {
            throw new InvalidUploadRequestException('No id given');
        }

        $normalizedServerId = trim((string) $serverId);

        if ($normalizedServerId === '') {
            throw new InvalidUploadRequestException('No id given');
        }

        try {
            return $this->serverIdCodec->decode($normalizedServerId);
        } catch (DecryptException $exception) {
            throw new InvalidUploadRequestException('Invalid encryption for id', 400, $exception);
        } catch (InvalidPathException $exception) {
            throw new InvalidUploadRequestException('Invalid encryption for id', 400, $exception);
        }
    }
}
