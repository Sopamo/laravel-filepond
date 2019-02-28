
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

When you receive the serverId from Filepond (that's the value which you get via the hidden input fields) in your controller you can decode it via:

```php
// Get the temporary path
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
    name: 'file',
    server: 'https://yourdomain.com/filepond/api/process',
})
```

