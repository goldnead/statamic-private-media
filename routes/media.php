<?php

use Goldnead\PrivateMedia\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

/*
 * Not routes/web.php on purpose: core would mount that file itself, without
 * the configurable prefix and without the switch. ServiceProvider::bootRoutes()
 * mounts this one.
 */

Route::get('{resource}/{path}', [MediaController::class, 'show'])
    ->where('resource', '[^/]+')
    ->where('path', '.+')
    ->name('show');
