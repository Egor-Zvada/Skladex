<?php

use App\Http\Controllers\OperationsController as C;
use Illuminate\Support\Facades\Route;

Route::prefix('maintenance')->name('maintenance.')->group(function () {
    Route::get('/', [C::class, 'index'])->name('index');
    Route::post('/work', [C::class, 'storeWork'])->name('work.store');
    Route::get('/work/{work}', [C::class, 'showWork'])->name('work.show');
    Route::post('/work/{work}/transition', [C::class, 'transitionWork'])->name('work.transition');
    Route::post('/work/{work}/assign', [C::class, 'updateWork'])->name('work.update');
    Route::post('/work/{work}/complete', [C::class, 'completeWork'])->name('work.complete');
    Route::post('/work/{work}/accept', [C::class, 'acceptWork'])->name('work.accept');
    Route::post('/work/{work}/correction', [C::class, 'correctWork'])->name('work.correct');
    Route::get('/requests', [C::class, 'requests'])->name('requests.index');
    Route::post('/requests', [C::class, 'storeRequest'])->name('requests.store');
    Route::post('/requests/{maintenanceRequest}/triage', [C::class, 'triageRequest'])->name('requests.triage');
    Route::get('/plans', [C::class, 'plans'])->name('plans.index');
    Route::post('/plans', [C::class, 'storePlan'])->name('plans.store');
    Route::get('/plans/{plan}', [C::class, 'showPlan'])->name('plans.show');
    Route::post('/plans/{plan}', [C::class, 'changePlan'])->name('plans.update');
    Route::post('/plans/{plan}/generate', [C::class, 'generatePlan'])->name('plans.generate');
    Route::post('/occurrences/{occurrence}/decide', [C::class, 'decideOccurrence'])->name('occurrences.decide');
});
Route::prefix('inventory')->name('inventory.')->group(function () {
    Route::get('/', [C::class, 'inventoryIndex'])->name('index');
    Route::post('/', [C::class, 'storeCampaign'])->name('store');
    Route::get('/{campaign}', [C::class, 'showCampaign'])->name('show');
    Route::post('/{campaign}/start', [C::class, 'startCampaign'])->name('start');
    Route::post('/{campaign}/observations', [C::class, 'observe'])->name('observations.store');
    Route::post('/{campaign}/reconcile', [C::class, 'reconcile'])->name('reconcile');
    Route::post('/{campaign}/resolutions', [C::class, 'resolve'])->name('resolutions.store');
    Route::post('/{campaign}/close', [C::class, 'closeCampaign'])->name('close');
    Route::post('/{campaign}/cancel', [C::class, 'cancelCampaign'])->name('cancel');
    Route::get('/{campaign}/report', [C::class, 'report'])->name('report');
});
