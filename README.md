

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

```php
composer require sopamo/laravel-filepond
```

If you need to edit the configuration, you can publish it with:

```php
php artisan vendor:publish --provider="Sopamo\LaravelFilepond\LaravelFilepondServiceProvider"
```

Included in this repo is a Filepond upload controller which is where you should direct uploads to. Upon upload the controller will return the `$serverId` which Filepond will send via a hidden input field (same name as the img) to be used in your own controller to move the file from temporary storage to somewhere permanent using the `getPathFromServerId($request->input('image'))` function.

```php
// Get the temporary path using the serverId returned by the upload function in `FilepondController.php`
$filepond = app(Sopamo\LaravelFilepond\Filepond::class);
$path = $filepond->getPathFromServerId($serverId);

// Move the file from the temporary path to the final location
$finalLocation = public_path('output.jpg');
\File::move($path, $finalLocation);
```

### Filepond setup

Set at least the following Filepond configuration:

```javascript
FilePond.setOptions({
  server: {
    url: '/filepond/api',
    process: '/process',
    headers: {
      'X-CSRF-TOKEN': '{{ csrf_token() }}'
    }
  }
});
```

