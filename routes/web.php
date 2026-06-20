<?php

use Illuminate\Support\Facades\Route;
use LogLens\Http\Controllers\AppController;
use LogLens\Http\Controllers\AssetController;

/*
| LogLens web (SPA shell + assets). Skipped entirely when `api_only` is on.
| The asset route's version segment makes stale bundles impossible after a
| composer update.
*/

Route::get('assets/{version}/{path}', [AssetController::class, 'show'])
    ->where('path', '.*')
    ->name('loglens.assets');

// SPA shell — the catch-all is last so it doesn't shadow the API/asset routes.
Route::get('/', [AppController::class, 'index'])->name('loglens.index');
Route::get('/{any}', [AppController::class, 'index'])->where('any', '^(?!api|assets).*$')->name('loglens.spa');
