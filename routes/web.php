<?php

use Illuminate\Support\Facades\Route;
use Sopamo\LaravelFilepond\Http\Controllers\FilepondController;

Route::prefix('api')->group(function () {
    Route::post('/process', [FilepondController::class, 'upload'])->name('filepond.upload');
    Route::delete('/process', [FilepondController::class, 'delete'])->name('filepond.delete');
});
