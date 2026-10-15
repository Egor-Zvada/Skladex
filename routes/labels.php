<?php

use App\Http\Controllers\LabelController;
use Illuminate\Support\Facades\Route;

Route::get('/labels', [LabelController::class, 'index'])->name('labels.index');
Route::post('/labels/profiles', [LabelController::class, 'store'])->middleware('permission:write')->name('labels.store');
Route::post('/labels/preview', [LabelController::class, 'preview'])->name('labels.preview');
Route::post('/labels/pdf', [LabelController::class, 'pdf'])->name('labels.pdf');
Route::post('/labels/test', [LabelController::class, 'test'])->name('labels.test');
