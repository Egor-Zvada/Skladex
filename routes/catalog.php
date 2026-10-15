<?php

use App\Http\Controllers\LicenseController;
use App\Http\Controllers\SoftwareController;
use Illuminate\Support\Facades\Route;

// Mounted by root under authenticated internal permissions and idempotency.
Route::prefix('software')->name('software.')->group(function () {
    Route::get('/', [SoftwareController::class, 'index'])->name('index');
    Route::post('/', [SoftwareController::class, 'store'])->name('store');
    Route::get('/{software}', [SoftwareController::class, 'show'])->name('show');
    Route::patch('/{software}', [SoftwareController::class, 'update'])->name('update');
    Route::post('/{software}/releases', [SoftwareController::class, 'releaseStore'])->name('releases.store');
    Route::post('/{software}/installations', [SoftwareController::class, 'installationStore'])->name('installations.store');
    Route::patch('/{software}/installations/{installation}', [SoftwareController::class, 'installationUpdate'])->name('installations.update');
});
Route::prefix('licenses')->name('licenses.')->group(function () {
    Route::get('/', [LicenseController::class, 'index'])->name('index');
    Route::post('/', [LicenseController::class, 'store'])->name('store');
    Route::get('/{license}', [LicenseController::class, 'show'])->name('show');
    Route::patch('/{license}', [LicenseController::class, 'update'])->name('update');
    Route::post('/{license}/key', [LicenseController::class, 'keyStore'])->name('key.store');
    Route::post('/{license}/assignments', [LicenseController::class, 'assign'])->name('assign');
    Route::post('/{license}/assignments/{assignment}/release', [LicenseController::class, 'release'])->name('release');
    Route::post('/{license}/reveal', [LicenseController::class, 'reveal'])->name('reveal');
});
