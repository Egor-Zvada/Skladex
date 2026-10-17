<?php

use App\Http\Controllers\WriteoffController as C;
use Illuminate\Support\Facades\Route;

Route::get('/writeoffs', [C::class, 'index'])->name('api.writeoffs.index');
Route::get('/writeoffs/{asset}', [C::class, 'show'])->name('api.writeoffs.show');
Route::post('/assets/{asset}/writeoff', [C::class, 'store'])->name('api.writeoffs.store');
Route::post('/writeoffs/{asset}/restore', [C::class, 'restore'])->name('api.writeoffs.restore');
Route::post('/writeoffs/{asset}/purge', [C::class, 'purge'])->name('api.writeoffs.purge');
