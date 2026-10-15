<?php

use App\Http\Controllers\ApiReferenceController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\KitController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:public')->group(function () {
    Route::get('/', [PublicController::class, 'home'])->name('public.home');
    Route::get('/lookup', [PublicController::class, 'resolve'])->name('public.resolve');
    Route::get('/p/a/{asset}', [PublicController::class, 'asset'])->name('public.asset');
    Route::get('/p/r/{location}', [PublicController::class, 'location'])->name('public.location');
    Route::get('/p/a/{asset}/qr.png', [PublicController::class, 'qrAsset'])->name('public.asset.qr');
    Route::get('/p/r/{location}/qr.png', [PublicController::class, 'qrLocation'])->name('public.location.qr');
    Route::get('/eq/{code}', [PublicController::class, 'legacyAsset'])->name('legacy.asset');
    Route::get('/equipment_card.php', [PublicController::class, 'legacyAsset']);
    Route::get('/cabinet.php', [PublicController::class, 'legacyLocation']);
    Route::get('/cabinet/{code}', [PublicController::class, 'legacyLocation'])->name('legacy.location');
    Route::get('/qr_scan.php', fn () => redirect()->route('public.home'));
});
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:login')->name('login.store');
    Route::get('/setup', [AuthController::class, 'setup'])->name('setup');
    Route::post('/setup', [AuthController::class, 'initialize'])->middleware('throttle:login')->name('setup.store');
});
Route::middleware(['auth', 'permission:read', 'idempotent'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/app', DashboardController::class)->name('dashboard');
    Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
    Route::get('/assets/create', [AssetController::class, 'create'])->name('assets.create');
    Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
    Route::get('/assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
    Route::get('/assets/{asset}/edit', [AssetController::class, 'edit'])->name('assets.edit');
    Route::patch('/assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
    Route::post('/assets/{asset}/move', [AssetController::class, 'move'])->name('assets.move');
    Route::post('/assets/{asset}/components', [AssetController::class, 'component'])->name('assets.components.store');
    Route::post('/assets/{asset}/components/{component}/remove', [AssetController::class, 'removeComponent'])->name('assets.components.remove');
    Route::post('/assets/{asset}/rack', [AssetController::class, 'rack'])->name('assets.rack');
    Route::post('/assets/{asset}/detach', [AssetController::class, 'detach'])->name('assets.detach');
    Route::post('/assets/{asset}/archive', [AssetController::class, 'archive'])->name('assets.archive');
    Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
    Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
    Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');
    Route::patch('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
    Route::get('/kits', [KitController::class, 'index'])->name('kits.index');
    Route::post('/kits', [KitController::class, 'store'])->name('kits.store');
    Route::post('/kits/{kit}/members', [KitController::class, 'member'])->name('kits.members');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/users', [SettingsController::class, 'user'])->name('settings.users.store');
    Route::post('/settings/users/{user}', [SettingsController::class, 'updateUser'])->name('settings.users.update');
    Route::post('/settings/password', [SettingsController::class, 'password'])->name('settings.password');
    Route::post('/settings/tokens', [SettingsController::class, 'token'])->name('settings.tokens.store');
    Route::post('/settings/tokens/{token}/revoke', [SettingsController::class, 'revoke'])->name('settings.tokens.revoke');
    Route::post('/settings/aliases', [SettingsController::class, 'alias'])->name('settings.aliases.store');
    Route::get('/api-reference', [ApiReferenceController::class, 'index'])->name('api.reference');
    Route::get('/api-reference/openapi.json', [ApiReferenceController::class, 'schema'])->name('api.schema');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}', [DocumentController::class, 'download'])->name('documents.download');
    foreach (['catalog', 'operations', 'labels'] as $file) {
        if (is_file(__DIR__."/$file.php")) {
            require __DIR__."/$file.php";
        }
    }
});
