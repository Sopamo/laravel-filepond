<?php

use Illuminate\Support\Facades\Route;
use Nocs\LaravelFilepond\Http\Controllers\FilepondController;

Route::group([
    'prefix' => 'api',
    //'middleware' => ['session'],
], function() {
    Route::post('/process', [FilepondController::class, 'upload'])->name('filepond.upload');
    Route::delete('/process', [FilepondController::class, 'delete'])->name('filepond.delete');
});
