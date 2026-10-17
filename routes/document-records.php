<?php

use App\Http\Controllers\GeneratedDocumentController as C;
use Illuminate\Support\Facades\Route;

Route::prefix('document-records')->name('document-records.')->group(function () {
    Route::get('/', [C::class, 'index'])->name('index');
    Route::get('/create', [C::class, 'create'])->name('create');
    Route::post('/', [C::class, 'store'])->name('store');
    Route::get('/{record}', [C::class, 'show'])->name('show');
    Route::patch('/{record}', [C::class, 'update'])->name('update');
    Route::post('/{record}/finalize', [C::class, 'finalize'])->name('finalize');
    Route::get('/{record}/pdf', [C::class, 'pdf'])->name('pdf');
});
