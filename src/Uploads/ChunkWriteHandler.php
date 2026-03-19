<?php

namespace Sopamo\LaravelFilepond\Uploads;

interface ChunkWriteHandler
{
    public function store(ChunkUploadRequest $chunkUploadRequest, string $content): void;
}
