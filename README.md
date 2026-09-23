<h1 align="center">
  Laravel FilePond Backend
</h1>

<p align="center">
  <strong>An all in one Laravel backend for <a href="https://pqina.nl/filepond/" target="_blank">FilePond</a></strong><br>
</p>
<br /><br />

## :rocket: Be up and running in 2 minutes

### Laravel setup

Requires PHP 8.2 or later and Laravel 11, 12 or 13 (Laravel 13 requires PHP 8.3 or later).

Require this package in the `composer.json` of your Laravel project.

```bash
composer require sopamo/laravel-filepond
```

If you need to edit the configuration, you can publish it with:

```bash
php artisan vendor:publish --provider="Sopamo\LaravelFilepond\LaravelFilepondServiceProvider"
```

```php
// Get the temporary path using the serverId returned by the upload function in `FilepondController.php`
$filepond = app(\Sopamo\LaravelFilepond\Filepond::class);
$disk = config('filepond.temporary_files_disk');

$path = $filepond->getPathFromServerId($serverId);
// Move the file from the temporary path to the final location
Storage::disk($disk)->move($path, 'uploads/output.jpg');
```

#### External storage

You can use any [Laravel disk](https://laravel.com/docs/12.x/filesystem) as the storage for temporary files. If you use a different disk for the temporary files and the final location, you will need to copy the file from the temporary location to the new disk then delete the temporary file yourself.

If you are using the default `local` disk, make sure the /storage/app/filepond directory exists in your project and is writable.

For the Azure OSS Blob Storage adapter, chunks are staged as blocks and committed on Azure without downloading and re-uploading the complete file. Other disks use streamed assembly. Azure support is optional; install and configure the Azure OSS disk in your application.

### Filepond client setup

This is the minimum Filepond JS configuration you need to set after installing laravel-filepond.

```javascript
FilePond.setOptions({
  server: {
    url: "/filepond/api",
    process: {
      url: "/process",
      headers: (file: File) => {
        // Send the original file name which will be used for chunked uploads
        return {
          "Upload-Name": file.name,
          "X-CSRF-TOKEN": "{{ csrf_token() }}",
          // Preserve the file MIME type when committing Azure blocks.
          "Content-Type": file.type,
        };
      },
    },
    revert: "/process",
    patch: "?patch=",
    headers: {
      "X-CSRF-TOKEN": "{{ csrf_token() }}",
    },
  },
});
```

## Package development

Please make sure all tests run successfully before submitting a PR.

### Testing

CI tests Laravel 11–13 with stable dependencies. The Laravel 11 jobs allow specific framework advisories during dependency resolution so compatibility can still be tested; the audit step continues to report them. These exceptions are confined to CI and do not change security settings in applications using this package.

Azure tests use the real Azure OSS SDK against a local HTTP fixture to verify block staging, commit requests, prefixes and MIME headers without an Azure account.

- Start a docker container to execute the tests in with ` docker run -it -v $PWD:/app composer /bin/bash`
- Run `composer install`
- Run `./vendor/bin/phpunit`
- Run `composer analyse`
