

<h1 align="center">
  Laravel FilePond Backend
</h1>

<p align="center">
  <strong>An all in one Laravel backend for <a href="https://pqina.nl/filepond/" target="_blank">FilePond</a></strong><br>
</p>
<p><b>This is beta version of Laravel Filepond</b></p>

<p>
    We currently support the `process` and `revert` methods and are securing those via the Laravel encryption/decryption methods.
</p>

## Main Features and Breaking Changes:
- Added support of <b>Chunked Uploads</b>
- The `getPathFromServerId` no longer returns the full path to the file. Instead it returns the "disk"-local path.

## :rocket: Be up and running in 2 minutes

### Laravel setup

Require this package in the `composer.json` of your Laravel project.

```bash
composer require sopamo/laravel-filepond:dev-beta/v1.0
```

If you need to edit the configuration, you can publish it with:

```bash
php artisan vendor:publish --provider="Sopamo\LaravelFilepond\LaravelFilepondServiceProvider"
```


From this version, Laravel Filepond will ship an <b>Upload Controller</b>, which supports chunked file uploads. The controller will map to following URLs:
```
PATCH /filepond/api -> Save Chunks
POST /filepond/api/process -> Intiate Upload
DELETE /filepond/api/process -> Delete
```
The controller will return `$serverId` as raw text response on `Initiate Upload`, which is encrypted relative file path, you can retrieve it by `getPathFromServerId($serverId)` function.

```php
// Get the temporary path using the serverId returned by the upload function in `FilepondController.php`
$filepond = app(Sopamo\LaravelFilepond\Filepond::class);
$disk = config('filepond.temporary_files_disk');

$path = $filepond->getPathFromServerId($serverId);
// Since this version doesn't return full path, we can construct it using Storage class.
$fullpath = Storage::disk($disk)->get($filePath);


// Move the file from the temporary path to the final location
$finalLocation = public_path('output.jpg');
\File::move($fullpath, $finalLocation);
```

#### External storage

You can use any [Laravel disk](https://laravel.com/docs/7.x/filesystem) as the storage for temporary files. If you use a different disk for temporary files and final location, you will need to copy the file from the temporary location to the new disk then delete the temporary file yourself.

If you are using the default `local` disk, make sure the /storage/app/filepond directory exists in your project and is writable.

### Filepond Client setup

This is the minimum Filepond JS configuration required to work with built in Filepond controller.

```javascript
FilePond.setOptions({
  server: {
    url: '/filepond/api',
    process: '/process',
    revert: '/process',
    patch: "?patch=",
    headers: {
      'X-CSRF-TOKEN': '{{ csrf_token() }}'
    }
  }
});
```

When uploading with Filepond Client, you can access the `serverId` by the name of the input, like this:
```php
$path = $filepond->getPathFromServerId($request->input("fileinput-name"));
```

