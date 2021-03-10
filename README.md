

<h1 align="center">
  Laravel FilePond Backend
</h1>

<p align="center">
  <strong>An all in one Laravel backend for <a href="https://pqina.nl/filepond/" target="_blank">FilePond</a></strong><br>
</p>
<p>
    We currently support the `process` and `revert` methods and are securing those via the Laravel encryption/decryption methods.
</p>

## :rocket: Be up and running in 2 minutes

### Laravel setup

Require this package in the `composer.json` of your Laravel project.

```bash
composer require sopamo/laravel-filepond
```

If you need to edit the configuration, you can publish it with:

```bash
php artisan vendor:publish --provider="Sopamo\LaravelFilepond\LaravelFilepondServiceProvider"
```

Included in this repo is a Filepond upload controller which is where you should direct uploads to. Upon upload the controller will return the `$serverId` which Filepond will send via a hidden input field (same name as the img) to be used in your own controller to move the file from temporary storage to somewhere permanent using the `getPathFromServerId($request->input('image'))` function.

```php
// Get the temporary path using the serverId returned by the upload function in `FilepondController.php`
$filepond = app(\Sopamo\LaravelFilepond\Filepond::class);
$path = $filepond->getPathFromServerId($serverId);

// Move the file from the temporary path to the final location
$finalLocation = public_path('output.jpg');
\File::move($path, $finalLocation);
```

#### External storage

You can use any [Laravel disk](https://laravel.com/docs/7.x/filesystem) as the storage for temporary files. If you use a different disk for temporary files and final location, you will need to copy the file from the temporary location to the new disk then delete the temporary file yourself.

If you are using the default `local` disk, make sure the /storage/app/filepond directory exists in your project and is writable.

### Filepond setup

Set at least the following Filepond configuration:

```javascript
FilePond.setOptions({
  server: {
    url: '/filepond/api',
    process: '/process',
    revert: '/process',
    headers: {
      'X-CSRF-TOKEN': '{{ csrf_token() }}'
    }
  }
});
```

