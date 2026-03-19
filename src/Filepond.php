<?php

namespace Sopamo\LaravelFilepond;

class Filepond
{
    public function __construct(private readonly ServerIdCodec $serverIdCodec)
    {
    }

    public function getServerIdFromPath(string $path): string
    {
        return $this->serverIdCodec->encode($path);
    }

    public function getPathFromServerId(string $serverId): string
    {
        return $this->serverIdCodec->decode($serverId);
    }
}
