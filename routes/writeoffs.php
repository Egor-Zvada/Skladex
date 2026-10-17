<?php

use App\Http\Controllers\WriteoffController as C;
use Illuminate\Support\Facades\Route;

Route::get('/writeoffs', [C::class, 'index'])->name('writeoffs.index');
Route::get('/writeoffs/{asset}', [C::class, 'show'])->name('writeoffs.show');
Route::post('/assets/{asset}/writeoff', [C::class, 'store'])->name('writeoffs.store');
Route::post('/writeoffs/{asset}/restore', [C::class, 'restore'])->name('writeoffs.restore');
Route::post('/writeoffs/{asset}/purge', [C::class, 'purge'])->name('writeoffs.purge');
