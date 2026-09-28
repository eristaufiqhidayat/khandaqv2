<?php

use App\Enums\Izin;
use App\Http\Controllers\Auth\GantiPasswordController;
use App\Http\Controllers\Auth\MasukController;
use App\Http\Controllers\BankImporController;
use App\Http\Controllers\BerandaController;
use App\Http\Controllers\KasirController;
use App\Http\Controllers\PersetujuanController;
use App\Http\Controllers\RingkasanController;
use App\Http\Controllers\SegeraController;
use App\Http\Controllers\SiaranWaController;
use App\Http\Controllers\SinkronisasiController;
use App\Http\Controllers\StatusPembayaranController;
use App\Http\Controllers\TunggakanController;
use App\Http\Controllers\TutupBukuController;
use App\Http\Controllers\VerifikasiController;
use App\Http\Controllers\Wali\PortalController;
use App\Http\Controllers\WebhookWaController;
use Illuminate\Support\Facades\Route;

// Halaman utama: tamu -> masuk, sudah masuk -> beranda (portal wali atau menu pertama staf).
Route::get('/', fn () => redirect()->route(auth()->check() ? 'beranda' : 'login'));

Route::middleware('guest')->group(function () {
    Route::get('/masuk', [MasukController::class, 'create'])->name('login');
    Route::post('/masuk', [MasukController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/keluar', [MasukController::class, 'destroy'])->name('logout');
    Route::get('/ganti-password', [GantiPasswordController::class, 'edit'])->name('password.ganti');
    Route::put('/ganti-password', [GantiPasswordController::class, 'update'])->name('password.ganti.simpan');
    Route::get('/beranda', BerandaController::class)->name('beranda');

    // Portal wali: hanya data anak sendiri (dijaga query & RaportPolicy).
    Route::middleware('role:wali_santri')->prefix('wali')->name('wali.')->group(function () {
        Route::get('/', PortalController::class)->name('beranda');
    });

    // Staf: setiap menu dijaga IZIN-nya, sama dengan yang menentukan menu tampil (config/khandaq-menu.php).
    $izin = fn (Izin $i) => 'permission:'.$i->value;

    Route::get('/ringkasan', RingkasanController::class)->middleware($izin(Izin::LaporanLihat))->name('laporan.ringkasan');

    Route::get('/status-pembayaran', StatusPembayaranController::class)->middleware($izin(Izin::LaporanLihat))->name('laporan.status');

    Route::middleware($izin(Izin::SetoranCatat))->prefix('kasir')->name('kasir.')->group(function () {
        Route::get('/', [KasirController::class, 'index'])->name('index');
        Route::post('/{santri}/setor', [KasirController::class, 'setor'])->name('setor');
        Route::post('/{santri}/tarik', [KasirController::class, 'tarik'])->name('tarik');
        Route::post('/{santri}/bayar/{tagihan}', [KasirController::class, 'bayar'])->name('bayar');
    });

    Route::get('/bukti-setoran/{mutasi}', [VerifikasiController::class, 'bukti'])->name('setoran.bukti');
    Route::middleware($izin(Izin::SetoranVerifikasi))->prefix('verifikasi')->name('verifikasi.')->group(function () {
        Route::get('/', [VerifikasiController::class, 'index'])->name('index');
        Route::post('/setoran/{mutasi}', [VerifikasiController::class, 'verifikasi'])->name('setoran');
        Route::post('/setoran/{mutasi}/tolak', [VerifikasiController::class, 'tolak'])->name('tolak');
        Route::post('/bank/{bank}/terima', [VerifikasiController::class, 'terima'])->name('terima');
        Route::post('/bank/{bank}/abaikan', [VerifikasiController::class, 'abaikan'])->name('abaikan');
    });

    Route::middleware($izin(Izin::KeringananSetujui))->prefix('persetujuan')->name('persetujuan.')->group(function () {
        Route::get('/', [PersetujuanController::class, 'index'])->name('index');
        Route::post('/{keringanan}/setujui', [PersetujuanController::class, 'setujui'])->name('setujui');
        Route::post('/{keringanan}/tolak', [PersetujuanController::class, 'tolak'])->name('tolak');
        Route::get('/{keringanan}/lampiran', [PersetujuanController::class, 'lampiran'])->name('lampiran');
    });

    Route::middleware($izin(Izin::TunggakanPutuskan))->prefix('tunggakan')->name('tunggakan.')->group(function () {
        Route::get('/', [TunggakanController::class, 'index'])->name('index');
        Route::post('/{peringatan}/putuskan', [TunggakanController::class, 'putuskan'])->name('putuskan');
    });

    Route::middleware($izin(Izin::BankImpor))->prefix('bank')->name('bank.')->group(function () {
        Route::get('/', [BankImporController::class, 'index'])->name('index');
        Route::post('/', [BankImporController::class, 'store'])->name('store');
    });

    Route::middleware($izin(Izin::TutupBuku))->prefix('tutup-buku')->name('tutupbuku.')->group(function () {
        Route::get('/', [TutupBukuController::class, 'index'])->name('index');
        Route::post('/', [TutupBukuController::class, 'store'])->name('store');
    });

    Route::middleware($izin(Izin::WaSiaran))->prefix('siaran')->name('siaran.')->group(function () {
        Route::get('/', [SiaranWaController::class, 'index'])->name('index');
        Route::post('/pratinjau', [SiaranWaController::class, 'pratinjau'])->name('pratinjau');
        Route::post('/', [SiaranWaController::class, 'store'])->name('store');
        Route::get('/{siaran}', [SiaranWaController::class, 'show'])->name('show');
        Route::post('/{siaran}/kirim', [SiaranWaController::class, 'kirim'])->name('kirim');
        Route::post('/{siaran}/batal', [SiaranWaController::class, 'batal'])->name('batal');
    });

    Route::middleware($izin(Izin::MigrasiJalankan))->prefix('sinkronisasi')->name('sinkronisasi.')->group(function () {
        Route::get('/', [SinkronisasiController::class, 'index'])->name('index');
        Route::post('/', [SinkronisasiController::class, 'store'])->name('store');
        Route::get('/{run}', [SinkronisasiController::class, 'show'])->name('show');
        Route::post('/{run}/batal', [SinkronisasiController::class, 'batal'])->name('batal');
    });

    // Menu yang layarnya belum dibuat: route & izin sudah aktif, isinya halaman "sedang dibangun".
    foreach ([
        ['pendaftaran', 'pendaftaran.index', Izin::PendaftaranProses],
        ['santri', 'santri.index', Izin::SantriKelola],
        ['raport', 'raport.index', Izin::RaportUnggah],
        ['pengeluaran', 'pengeluaran.index', Izin::PengeluaranCatat],
        ['tarif', 'tarif.index', Izin::TarifKelola],
        ['potongan', 'potongan.index', Izin::PengecualianKelola],
        ['periode', 'periode.index', Izin::PeriodeKelola],
        ['master-keuangan', 'masterkeu.index', Izin::MasterKeuanganKelola],
        ['pengguna', 'pengguna.index', Izin::PenggunaKelola],
        ['hak-akses', 'hakakses.index', Izin::HakAksesKelola],
    ] as [$uri, $nama, $i]) {
        Route::get('/'.$uri, SegeraController::class)->middleware($izin($i))->name($nama);
    }
});

// Webhook gateway WhatsApp: tanpa login, dikecualikan dari CSRF (bootstrap/app.php).
Route::prefix('webhook/wa')->group(function () {
    Route::get('/meta', [WebhookWaController::class, 'verifikasiMeta']);
    Route::post('/meta', [WebhookWaController::class, 'meta']);
    Route::post('/ngirimwa', [WebhookWaController::class, 'ngirimwa']);
});
