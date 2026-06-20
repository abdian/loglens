<?php

use Illuminate\Support\Facades\Route;
use LogLens\Http\Controllers\DiagnosticsController;
use LogLens\Http\Controllers\DownloadController;
use LogLens\Http\Controllers\FileOpsController;
use LogLens\Http\Controllers\FilesController;
use LogLens\Http\Controllers\GroupsController;
use LogLens\Http\Controllers\IndexController;
use LogLens\Http\Controllers\MailController;
use LogLens\Http\Controllers\SavedSearchController;
use LogLens\Http\Controllers\SearchController;
use LogLens\Http\Controllers\StatsController;
use LogLens\Http\Controllers\TailController;

/*
| LogLens JSON API. The enclosing group (service provider) already applies the
| route prefix, the shared middleware stack and the Authorize gate identically
| to every route here and on the web routes.
*/

Route::prefix('api')->name('loglens.api.')->group(function () {
    // Diagnostics
    Route::get('diagnostics', [DiagnosticsController::class, 'show'])->name('diagnostics');

    // Saved searches (LogLens-owned storage)
    Route::get('saved-searches', [SavedSearchController::class, 'index'])->name('saved.index');
    Route::post('saved-searches', [SavedSearchController::class, 'store'])->name('saved.store');
    Route::delete('saved-searches/{id}', [SavedSearchController::class, 'destroy'])->name('saved.destroy');

    // File discovery + batch (literal routes before the {file} wildcard)
    Route::get('files', [FilesController::class, 'index'])->name('files');
    Route::post('files/batch', [FileOpsController::class, 'batch'])->name('files.batch');

    // Tail transports
    Route::get('tail/info', [TailController::class, 'info'])->name('tail.info');
    Route::middleware('throttle:loglens-tail')->group(function () {
        Route::get('tail/stream', [TailController::class, 'stream'])->name('tail.stream');
        Route::get('tail/poll', [TailController::class, 'poll'])->name('tail.poll');
    });

    // Signed download fetch + zip
    Route::get('download', [DownloadController::class, 'zip'])->name('download.zip');
    Route::get('download/{file}', [DownloadController::class, 'fetch'])
        ->middleware('signed')->name('download.fetch');

    // Per-file browsing
    Route::get('files/{file}/open', [FilesController::class, 'open'])->name('files.open');
    Route::get('files/{file}/entries', [FilesController::class, 'entries'])->name('files.entries');
    Route::get('files/{file}/entries/{seq}', [FilesController::class, 'entry'])->whereNumber('seq')->name('files.entry');
    Route::get('files/{file}/expand', [FilesController::class, 'expand'])->name('files.expand');
    Route::get('files/{file}/jump', [FilesController::class, 'jump'])->name('files.jump');
    Route::get('files/{file}/permalink/{seq}', [FilesController::class, 'permalink'])->whereNumber('seq')->name('files.permalink');
    Route::get('files/{file}/context/{seq}', [FilesController::class, 'context'])->whereNumber('seq')->name('files.context');
    Route::get('files/{file}/levels', [FilesController::class, 'levels'])->name('files.levels');
    Route::get('files/{file}/mail/{seq}', [MailController::class, 'preview'])->whereNumber('seq')->name('files.mail');

    // Search (rate-limited)
    Route::get('files/{file}/search', [SearchController::class, 'search'])
        ->middleware('throttle:loglens-search')->name('files.search');

    // Error grouping (Issues) + analytics — each opens a store and can fan out,
    // so they share a dedicated (generous) rate-limit bucket.
    Route::middleware('throttle:loglens-analytics')->group(function () {
        Route::get('files/{file}/groups', [GroupsController::class, 'index'])->name('files.groups');
        Route::get('files/{file}/groups/new', [GroupsController::class, 'newSince'])->name('files.groups.new');
        Route::get('files/{file}/groups/{fp}/sparkline', [StatsController::class, 'groupSparkline'])->name('files.groups.sparkline');
        Route::get('files/{file}/histogram', [StatsController::class, 'histogram'])->name('files.histogram');
        Route::get('files/{file}/sparkline', [StatsController::class, 'sparkline'])->name('files.sparkline');
    });

    // Index management (gated, rate-limited)
    Route::get('files/{file}/index', [IndexController::class, 'status'])->name('files.index.status');
    Route::middleware('throttle:loglens-index')->group(function () {
        Route::post('files/{file}/index', [IndexController::class, 'build'])->name('files.index.build');
        Route::post('files/{file}/index/rebuild', [IndexController::class, 'rebuild'])->name('files.index.rebuild');
    });

    // Download signing + destructive ops
    Route::get('files/{file}/download', [DownloadController::class, 'sign'])->name('files.download.sign');
    Route::get('files/{file}/writability', [FileOpsController::class, 'writability'])->name('files.writability');
    Route::post('files/{file}/clear', [FileOpsController::class, 'clear'])->name('files.clear');
    Route::delete('files/{file}/entries/{seq}', [FileOpsController::class, 'deleteEntry'])->whereNumber('seq')->name('files.entry.delete');
    Route::delete('files/{file}', [FileOpsController::class, 'delete'])->name('files.delete');
});
