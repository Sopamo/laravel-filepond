

<h1 align="center">
  Laravel FilePond Backend
</h1>

<p align="center">
  <strong>An all in one Laravel backend for <a href="https://pqina.nl/filepond/" target="_blank">FilePond</a></strong><br>
</p>
<br /><br />
<div align="center">

>**Note**
>
>If you are using Laravel with Vue.js, check out another package from us which just went to alpha:
>
>[Check out double](https://github.com/Sopamo/double-vue)

</div>
<br /><br />

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


```php
// Get the temporary path using the serverId returned by the upload function in `FilepondController.php`
$filepond = app(\Sopamo\LaravelFilepond\Filepond::class);
$disk = config('filepond.temporary_files_disk');

$path = $filepond->getPathFromServerId($serverId);
$fullpath = Storage::disk($disk)->get($filePath);


// Move the file from the temporary path to the final location
$finalLocation = public_path('output.jpg');
\File::move($fullpath, $finalLocation);
```

#### External storage

You can use any [Laravel disk](https://laravel.com/docs/7.x/filesystem) as the storage for temporary files. If you use a different disk for the temporary files and the final location, you will need to copy the file from the temporary location to the new disk then delete the temporary file yourself.

If you are using the default `local` disk, make sure the /storage/app/filepond directory exists in your project and is writable.

### Filepond client setup

This is the minimum Filepond JS configuration you need to set after installing laravel-filepond.

```javascript
FilePond.setOptions({
  server: {
    url: '/filepond/api',
    process: {
      url: "/process",
      headers: (file: File) => {
        // Send the original file name which will be used for chunked uploads
        return {
          "Upload-Name": file.name,
          "X-CSRF-TOKEN": "{{ csrf_token() }}",
        }
      },
    },
    revert: '/process',
    patch: "?patch=",
    headers: {
      'X-CSRF-TOKEN': '{{ csrf_token() }}'
    }
  }
});
```

## Package development
Please make sure all tests run successfully before submitting a PR.
### Testing
 - Start a docker container to execute the tests in with ` docker run -it -v $PWD:/app composer /bin/bash`
 - Run `composer install`
 - Run `./vendor/bin/phpunit`
