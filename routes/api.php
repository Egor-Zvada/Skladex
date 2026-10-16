<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\KitController;
use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'permission:read', 'throttle:api', 'idempotent'])->group(function () {
    Route::get('/buildings', [BuildingController::class, 'index'])->name('api.buildings.index');
    Route::post('/buildings', [BuildingController::class, 'store'])->name('api.buildings.store');
    Route::get('/buildings/{building}', [BuildingController::class, 'show'])->name('api.buildings.show');
    Route::patch('/buildings/{building}', [BuildingController::class, 'update'])->name('api.buildings.update');
    Route::post('/buildings/{building}/archive', [BuildingController::class, 'archive'])->name('api.buildings.archive');
    Route::get('/categories', [CategoryController::class, 'index'])->name('api.categories.index');
    Route::get('/assets', [AssetController::class, 'index'])->name('api.assets.index');
    Route::post('/assets', [AssetController::class, 'store'])->name('api.assets.store');
    Route::get('/assets/{asset}', [AssetController::class, 'show'])->name('api.assets.show');
    Route::patch('/assets/{asset}', [AssetController::class, 'update'])->name('api.assets.update');
    Route::post('/assets/{asset}/move', [AssetController::class, 'move'])->name('api.assets.move');
    Route::post('/assets/{asset}/components', [AssetController::class, 'component'])->name('api.assets.components.store');
    Route::post('/assets/{asset}/components/{component}/remove', [AssetController::class, 'removeComponent'])->name('api.assets.components.remove');
    Route::post('/assets/{asset}/rack', [AssetController::class, 'rack'])->name('api.assets.rack');
    Route::post('/assets/{asset}/detach', [AssetController::class, 'detach'])->name('api.assets.detach');
    Route::post('/assets/{asset}/archive', [AssetController::class, 'archive'])->name('api.assets.archive');
    Route::get('/locations', [LocationController::class, 'index'])->name('api.locations.index');
    Route::post('/locations', [LocationController::class, 'store'])->name('api.locations.store');
    Route::get('/locations/{location}', [LocationController::class, 'show'])->name('api.locations.show');
    Route::patch('/locations/{location}', [LocationController::class, 'update'])->name('api.locations.update');
    Route::get('/kits', [KitController::class, 'index'])->name('api.kits.index');
    Route::post('/kits', [KitController::class, 'store'])->name('api.kits.store');
    Route::post('/kits/{kit}/members', [KitController::class, 'member'])->name('api.kits.members');
    foreach (['catalog-api', 'operations-api', 'labels-api'] as $file) {
        if (is_file(__DIR__."/$file.php")) {
            require __DIR__."/$file.php";
        }
    }
});
