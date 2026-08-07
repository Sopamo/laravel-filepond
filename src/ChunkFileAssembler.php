<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Contracts\Filesystem\Filesystem;
use Sopamo\LaravelFilepond\Exceptions\UploadException;

final class ChunkFileAssembler
{
    private const STREAM_BUFFER_BYTES = 1024 * 1024;

    /** @param array<int, array{size: int, checksum: string}> $parts */
    public function assemble(
        Filesystem $storage,
        TemporaryUpload $upload,
        string $chunkDirectory,
        array $parts,
        int $length,
    ): void {
        $temporaryStream = tmpfile();
        if ($temporaryStream === false) {
            throw new UploadException('A temporary merge stream could not be created.', 500);
        }

        try {
            $offsets = array_map('intval', array_keys($parts));
            sort($offsets, SORT_NUMERIC);
            $totalBytes = 0;

            foreach ($offsets as $offset) {
                $chunkStream = $storage->readStream($chunkDirectory.'/parts/'.$offset);
                if (!is_resource($chunkStream)) {
                    throw new UploadException('An uploaded chunk could not be read.', 500);
                }

                try {
                    $totalBytes = $this->appendVerifiedPart(
                        $chunkStream,
                        $temporaryStream,
                        $parts[$offset],
                        $totalBytes,
                        $length,
                    );
                } finally {
                    fclose($chunkStream);
                }
            }

            if ($totalBytes !== $length) {
                throw new UploadException('The uploaded chunks failed integrity validation.', 500);
            }

            rewind($temporaryStream);
            if (!$storage->put($upload->path, $temporaryStream)) {
                throw new UploadException('The completed upload could not be stored.', 500);
            }
        } finally {
            if (is_resource($temporaryStream)) {
                fclose($temporaryStream);
            }
        }
    }

    /**
     * @param resource $chunkStream
     * @param resource $temporaryStream
     * @param array{size: int, checksum: string} $part
     */
    private function appendVerifiedPart(
        $chunkStream,
        $temporaryStream,
        array $part,
        int $totalBytes,
        int $length,
    ): int {
        $partBytes = 0;
        $hashContext = hash_init('sha256');

        while (!feof($chunkStream)) {
            $buffer = fread($chunkStream, self::STREAM_BUFFER_BYTES);
            if ($buffer === false || ($buffer === '' && !feof($chunkStream))) {
                throw new UploadException('An uploaded chunk could not be read.', 500);
            }

            if ($buffer === '') {
                break;
            }

            $bufferBytes = strlen($buffer);
            if ($bufferBytes > $part['size'] - $partBytes || $bufferBytes > $length - $totalBytes) {
                throw new UploadException('The uploaded chunks failed integrity validation.', 500);
            }

            hash_update($hashContext, $buffer);
            $this->writeBuffer($temporaryStream, $buffer);
            $partBytes += $bufferBytes;
            $totalBytes += $bufferBytes;
        }

        $actualChecksum = hash_final($hashContext);
        if ($partBytes !== $part['size'] || !hash_equals($part['checksum'], $actualChecksum)) {
            throw new UploadException('The uploaded chunks failed integrity validation.', 500);
        }

        return $totalBytes;
    }

    /** @param resource $stream */
    private function writeBuffer($stream, string $buffer): void
    {
        $writtenBytes = 0;
        $bufferBytes = strlen($buffer);

        while ($writtenBytes < $bufferBytes) {
            $written = fwrite($stream, substr($buffer, $writtenBytes));
            if ($written === false || $written === 0) {
                throw new UploadException('An uploaded chunk could not be merged.', 500);
            }

            $writtenBytes += $written;
        }
    }
}
