# Laravel FilePond Backend

A standalone, storage-neutral Laravel backend for FilePond process, chunk, and revert requests.

Version 3 keeps the version 2 HTTP endpoints and public path helpers while replacing the controller-heavy implementation with explicit validation, storage-neutral upload services, chunk manifests, cache locking, integrity checks, and streamed assembly.

The package uses Laravel's filesystem abstraction and contains no Azure, S3, or vendor SDK integration. The configured Laravel disk owns storage transport and URL encoding.

## Requirements

- PHP 8.2 or newer
- Laravel 11, 12, or 13

Laravel 11 compatibility is tested, but Laravel 11 itself no longer receives security fixes. Composer may require Laravel 11 applications to explicitly handle unresolved framework advisories; this package does not disable Composer security checks for consumers.

```bash
composer require sopamo/laravel-filepond:^3.0
php artisan vendor:publish --provider="Sopamo\LaravelFilepond\LaravelFilepondServiceProvider"
```

The package registers its routes by default, matching version 2. Configure the published `config/filepond.php` with authentication, authorization, and rate-limiting middleware appropriate for the host application:

```php
'middleware' => ['api', 'auth:sanctum', 'can:upload-files', 'throttle:filepond'],
```

Set `FILEPOND_ROUTES_ENABLED=false` when the host application registers the controller routes itself.

Configure FilePond to use the package endpoints:

```javascript
FilePond.setOptions({
  chunkUploads: true,
  server: {
    url: '/filepond/api',
    process: {
      url: '/process',
      headers: (file) => ({
        'Upload-Name': file.name,
        'Upload-Length': file.size,
      }),
    },
    patch: '?patch=',
    revert: '/process',
  },
})
```

## Consuming an upload

The process response is an encrypted bearer handle, not a public file URL. Existing version 2 consumers can continue resolving the temporary path:

```php
use Sopamo\LaravelFilepond\Filepond;

$path = app(Filepond::class)->getPathFromServerId($serverId);
```

New code can resolve the full upload description:

```php
$upload = app(Filepond::class)->resolveServerId($serverId);

$upload->disk;
$upload->path;
$upload->originalName;
```

Each upload is isolated in a generated directory while retaining the client filename:

```text
filepond/{ULID}/Safety+guide.pdf
```

Directory separators are removed from untrusted client filenames. Other valid filename characters, including `+`, spaces, `%`, `#`, and Unicode, remain unchanged. Applications own the final destination, visibility, move or copy operation, and URL. Generate URLs through the final Laravel disk's `url()` or `temporaryUrl()` method instead of encoding storage paths manually.

## Chunk uploads

Chunk parts and manifests are stored on the configured temporary disk. Final assembly uses streams and the same Laravel filesystem adapter. Concurrent requests for one upload are serialized with a Laravel cache lock.

Configure `FILEPOND_LOCK_STORE` to use a cache store shared by every PHP worker and replica, such as Redis or the database cache store. Set `FILEPOND_LOCK_SECONDS` above the longest expected PATCH and final assembly request.

`FILEPOND_TEMP_PATH` and `FILEPOND_CHUNKS_PATH` must be non-empty, distinct, relative storage prefixes without traversal or NUL segments.

## Cross-origin clients

For clients on another origin, allow `POST`, `PATCH`, `DELETE`, and `OPTIONS`. Allow the `Content-Type`, `Upload-Length`, `Upload-Name`, and `Upload-Offset` request headers plus authentication headers, and expose the `Upload-Offset` response header.

See [UPGRADE.md](UPGRADE.md) for version 2 compatibility details and [SECURITY.md](SECURITY.md) for the security model.

## Development

```bash
composer install
vendor/bin/phpunit
```
