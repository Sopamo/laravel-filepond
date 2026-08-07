<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Str;
use JsonException;
use Sopamo\LaravelFilepond\Exceptions\InvalidPathException;

final class ServerIdCodec
{
    public function __construct(private readonly StringEncrypter $encrypter)
    {
    }

    public function encode(TemporaryUpload $upload): string
    {
        return $this->encodeForTransport($this->encrypter->encryptString(json_encode([
            'version' => 3,
            'id' => $upload->id,
            'disk' => $upload->disk,
            'path' => $upload->path,
            'original_name' => $upload->originalName,
            'legacy' => $upload->legacy,
        ], JSON_THROW_ON_ERROR)));
    }

    public function encodeLegacyPath(string $path): string
    {
        $this->assertTemporaryPath($path);
        $normalizedPath = trim($this->normalizePath($path), '/');

        return $this->encrypter->encryptString($normalizedPath);
    }

    public function decode(string $serverId): TemporaryUpload
    {
        if (trim($serverId) === '') {
            throw new InvalidPathException('No upload id was provided.');
        }

        try {
            $decrypted = $this->encrypter->decryptString($this->decodeFromTransport($serverId));
        } catch (DecryptException $exception) {
            throw new InvalidPathException('The upload id is invalid.', 400, $exception);
        }

        try {
            $payload = json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->decodeLegacyPath($decrypted);
        }

        if (!is_array($payload)) {
            throw new InvalidPathException('The upload id is invalid.');
        }

        $legacy = $payload['legacy'] ?? false;
        if (($payload['version'] ?? null) !== 3
            || !is_string($payload['id'] ?? null)
            || !is_string($payload['disk'] ?? null) || $payload['disk'] === ''
            || !is_string($payload['path'] ?? null) || !is_string($payload['original_name'] ?? null)
            || !is_bool($legacy)) {
            throw new InvalidPathException('The upload id is invalid.');
        }

        $this->assertTemporaryPath($payload['path']);
        $normalizedPath = trim($this->normalizePath($payload['path']), '/');
        if ((!$legacy && !Str::isUlid($payload['id']))
            || ($legacy && !hash_equals(hash('sha256', $normalizedPath), $payload['id']))) {
            throw new InvalidPathException('The upload id is invalid.');
        }

        return new TemporaryUpload(
            $payload['id'],
            $payload['disk'],
            $normalizedPath,
            $payload['original_name'],
            $legacy,
        );
    }

    public function assertTemporaryPath(string $path): void
    {
        StoragePath::assertDistinctRoots();
        $root = StoragePath::temporaryFilesRoot();
        $normalizedPath = trim($this->normalizePath($path), '/');

        if ($root === '' || $normalizedPath === $root || !str_starts_with($normalizedPath, $root.'/')) {
            throw new InvalidPathException('The upload path is invalid.');
        }

        foreach (explode('/', $normalizedPath) as $segment) {
            if ($segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                throw new InvalidPathException('The upload path is invalid.');
            }
        }
    }

    private function decodeLegacyPath(string $path): TemporaryUpload
    {
        $this->assertTemporaryPath($path);
        $normalizedPath = trim($this->normalizePath($path), '/');
        $root = StoragePath::temporaryFilesRoot();
        $relativePath = substr($normalizedPath, strlen($root) + 1);

        return new TemporaryUpload(
            explode('/', $relativePath)[0],
            (string) config('filepond.temporary_files_disk', 'local'),
            $normalizedPath,
            basename($normalizedPath),
            true,
        );
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function encodeForTransport(string $encryptedPayload): string
    {
        return rtrim(strtr($encryptedPayload, '+/', '-_'), '=');
    }

    private function decodeFromTransport(string $serverId): string
    {
        $encryptedPayload = strtr(str_replace(' ', '+', $serverId), '-_', '+/');
        $missingPadding = strlen($encryptedPayload) % 4;

        if ($missingPadding !== 0) {
            $encryptedPayload .= str_repeat('=', 4 - $missingPadding);
        }

        return $encryptedPayload;
    }
}
