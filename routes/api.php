<?php

use App\Http\Controllers\Api\V1\AkunController;
use App\Http\Controllers\Api\V1\AnakController;
use App\Http\Middleware\TokenWali;
use Illuminate\Support\Facades\Route;

/*
| API aplikasi Android wali santri (lembah_arafah_v3). Dokumentasi: docs/API-WALI.md.
| Semua rute kecuali /masuk wajib "Authorization: Bearer <token>".
*/
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/masuk', [AkunController::class, 'masuk'])->name('masuk');
    Route::post('/masuk/google', [AkunController::class, 'masukGoogle'])->name('masuk.google');

    Route::middleware([TokenWali::class, 'throttle:120,1'])->group(function () {
        Route::post('/keluar', [AkunController::class, 'keluar'])->name('keluar');
        Route::get('/saya', [AkunController::class, 'saya'])->name('saya');
        Route::put('/saya', [AkunController::class, 'ubah'])->name('saya.ubah');
        Route::post('/saya/password', [AkunController::class, 'gantiPassword'])->name('saya.password');
        Route::post('/saya/google', [AkunController::class, 'tautkanGoogle'])->name('saya.google');
        Route::delete('/saya/google', [AkunController::class, 'lepasGoogle'])->name('saya.google.lepas');
        Route::get('/kalender', [AnakController::class, 'kalender'])->name('kalender');

        Route::prefix('anak/{santri}')->name('anak.')->group(function () {
            Route::get('/', [AnakController::class, 'show'])->name('show');
            Route::get('/foto', [AnakController::class, 'foto'])->name('foto');
            Route::get('/tabungan', [AnakController::class, 'tabungan'])->name('tabungan');
            Route::get('/raport', [AnakController::class, 'raport'])->name('raport');
            Route::get('/raport/{raport}/pdf', [AnakController::class, 'raportPdf'])->name('raport.pdf');
            Route::post('/lapor-transfer', [AnakController::class, 'laporTransfer'])->name('lapor');
        });
    });
});
