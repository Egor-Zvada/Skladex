<?php

use App\Http\Controllers\LabelController;
use Illuminate\Support\Facades\Route;

Route::get('/labels', [LabelController::class, 'index'])->name('api.labels.index');
Route::post('/labels/profiles', [LabelController::class, 'store'])->middleware('permission:write')->name('api.labels.store');
Route::post('/labels/preview', [LabelController::class, 'preview'])->name('api.labels.preview');
Route::post('/labels/pdf', [LabelController::class, 'pdf'])->name('api.labels.pdf');
Route::post('/labels/test', [LabelController::class, 'test'])->name('api.labels.test');
