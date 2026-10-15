<?php

use App\Http\Controllers\OperationsApiController as C;
use Illuminate\Support\Facades\Route;

// Root mounts this under /api/v1 with auth, permission:read and idempotency.
// Names are already prefixed with api.; do not add a second name prefix.
Route::prefix('maintenance')->name('api.maintenance.')->group(function () {
    Route::get('/work', [C::class, 'workIndex'])->name('work.index');
    Route::post('/work', [C::class, 'workStore'])->name('work.store');
    Route::post('/completed-work', [C::class, 'completedStore'])->name('work.completed-store');
    Route::get('/work/{work}', [C::class, 'workShow'])->name('work.show');
    Route::patch('/work/{work}', [C::class, 'workUpdate'])->name('work.update');
    Route::post('/work/{work}/transition', [C::class, 'workTransition'])->name('work.transition');
    Route::post('/work/{work}/complete', [C::class, 'workComplete'])->name('work.complete');
    Route::post('/work/{work}/accept', [C::class, 'workAccept'])->name('work.accept');
    Route::post('/work/{work}/correction', [C::class, 'workCorrect'])->name('work.correct');
    Route::get('/requests', [C::class, 'requestIndex'])->name('requests.index');
    Route::post('/requests', [C::class, 'requestStore'])->name('requests.store');
    Route::post('/requests/{maintenanceRequest}/triage', [C::class, 'requestTriage'])->name('requests.triage');
    Route::get('/plans', [C::class, 'planIndex'])->name('plans.index');
    Route::post('/plans', [C::class, 'planStore'])->name('plans.store');
    Route::get('/plans/{plan}', [C::class, 'planShow'])->name('plans.show');
    Route::patch('/plans/{plan}', [C::class, 'planUpdate'])->name('plans.update');
    Route::post('/plans/{plan}/generate', [C::class, 'planGenerate'])->name('plans.generate');
    Route::post('/occurrences/{occurrence}/decide', [C::class, 'occurrenceDecide'])->name('occurrences.decide');
});
Route::prefix('inventory')->name('api.inventory.')->group(function () {
    Route::get('/', [C::class, 'campaignIndex'])->name('index');
    Route::post('/', [C::class, 'campaignStore'])->name('store');
    Route::get('/{campaign}', [C::class, 'campaignShow'])->name('show');
    Route::post('/{campaign}/start', [C::class, 'campaignStart'])->name('start');
    Route::post('/{campaign}/observations', [C::class, 'observationStore'])->name('observations.store');
    Route::post('/{campaign}/reconcile', [C::class, 'campaignReconcile'])->name('reconcile');
    Route::post('/{campaign}/resolutions', [C::class, 'resolutionStore'])->name('resolutions.store');
    Route::post('/{campaign}/close', [C::class, 'campaignClose'])->name('close');
    Route::post('/{campaign}/cancel', [C::class, 'campaignCancel'])->name('cancel');
    Route::get('/{campaign}/report',[C::class, 'campaignReport'])->name('report');
});
