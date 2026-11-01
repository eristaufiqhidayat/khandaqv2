<?php

use App\Enums\Izin;
use App\Http\Controllers\Auth\GantiPasswordController;
use App\Http\Controllers\Auth\MasukController;
use App\Http\Controllers\BankImporController;
use App\Http\Controllers\BerandaController;
use App\Http\Controllers\KalenderController;
use App\Http\Controllers\FotoSantriController;
use App\Http\Controllers\WaliSantriController;
use App\Http\Controllers\Wali\ProfilController;
use App\Http\Controllers\Wali\LaporTransferController;
use App\Http\Controllers\TarifController;
use App\Http\Controllers\PotonganController;
use App\Http\Controllers\PeriodeController;
use App\Http\Controllers\PengeluaranController;
use App\Http\Controllers\PenggunaController;
use App\Http\Controllers\MasterKeuanganController;
use App\Http\Controllers\KenaikanController;
use App\Http\Controllers\HakAksesController;
use App\Http\Controllers\KasirController;
use App\Http\Controllers\PendaftaranController;
use App\Http\Controllers\PersetujuanController;
use App\Http\Controllers\RaportController;
use App\Http\Controllers\RingkasanController;
use App\Http\Controllers\SantriController;
use App\Http\Controllers\SiaranWaController;
use App\Http\Controllers\SinkronisasiController;
use App\Http\Controllers\StatusPembayaranController;
use App\Http\Controllers\TunggakanController;
use App\Http\Controllers\TutupBukuController;
use App\Http\Controllers\GajiController;
use App\Http\Controllers\Guru;
use App\Http\Controllers\MapelController;
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
        Route::get('/raport', [PortalController::class, 'raport'])->name('raport');
        Route::get('/tabungan', [PortalController::class, 'tabungan'])->name('tabungan');
        Route::get('/kalender', [PortalController::class, 'kalender'])->name('kalender');
        Route::get('/data', [PortalController::class, 'data'])->name('data');
        Route::get('/lapor-transfer', [LaporTransferController::class, 'create'])->name('lapor');
        Route::post('/lapor-transfer', [LaporTransferController::class, 'store'])->name('lapor.store');
        Route::get('/profil', [ProfilController::class, 'edit'])->name('profil');
        Route::put('/profil', [ProfilController::class, 'update'])->name('profil.update');
    });

    // Ubah data wali oleh petugas (dari detail santri atau tab Wali santri di Pengguna).
    Route::middleware('permission:'.Izin::PenggunaKelola->value.'|'.Izin::SantriKelola->value.'|'.Izin::AkunWaliReset->value)->prefix('wali-santri')->name('walisantri.')->group(function () {
        Route::get('/{wali}/ubah', [WaliSantriController::class, 'edit'])->name('edit');
        Route::put('/{wali}', [WaliSantriController::class, 'update'])->name('update');
        Route::post('/{wali}/password', [WaliSantriController::class, 'password'])->name('password');
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

    // Foto santri (file di storage, bukan publik): wali hanya anaknya sendiri, staf semua. Dicek di controller.
    Route::get('/foto-santri/{santri}', [FotoSantriController::class, 'tampil'])->name('santri.foto');

    Route::get('/bukti-setoran/{mutasi}', [VerifikasiController::class, 'bukti'])->name('setoran.bukti');
    Route::middleware($izin(Izin::SetoranVerifikasi))->prefix('verifikasi')->name('verifikasi.')->group(function () {
        Route::get('/', [VerifikasiController::class, 'index'])->name('index');
        Route::post('/setoran/{mutasi}', [VerifikasiController::class, 'verifikasi'])->name('setoran');
        Route::post('/setoran/{mutasi}/tolak', [VerifikasiController::class, 'tolak'])->name('tolak');
        Route::post('/bank/{bank}/terima', [VerifikasiController::class, 'terima'])->name('terima');
        Route::post('/bank/{bank}/abaikan', [VerifikasiController::class, 'abaikan'])->name('abaikan');
    });

    Route::middleware($izin(Izin::SantriKelola))->prefix('santri')->name('santri.')->group(function () {
        Route::get('/', [SantriController::class, 'index'])->name('index');
        Route::get('/baru', [SantriController::class, 'create'])->name('create');
        Route::post('/', [SantriController::class, 'store'])->name('store');
        Route::get('/{santri}', [SantriController::class, 'show'])->name('show');
        Route::get('/{santri}/ubah', [SantriController::class, 'edit'])->name('edit');
        Route::put('/{santri}', [SantriController::class, 'update'])->name('update');
        Route::post('/{santri}/aktifkan', [SantriController::class, 'aktifkan'])->name('aktifkan');
        Route::post('/{santri}/keluarkan', [SantriController::class, 'keluarkan'])->name('keluarkan');
        Route::post('/{santri}/foto', [FotoSantriController::class, 'simpan'])->name('foto.simpan');
        Route::post('/{santri}/foto/hapus', [FotoSantriController::class, 'hapus'])->name('foto.hapus');
        Route::post('/{santri}/wali', [SantriController::class, 'waliStore'])->name('wali.store');
        Route::post('/{santri}/wali/{wali}/lepas', [SantriController::class, 'waliLepas'])->name('wali.lepas');
        Route::post('/{santri}/wali/{wali}/reset', [SantriController::class, 'waliReset'])->name('wali.reset');
    });
    Route::middleware($izin(Izin::DataWaliVerifikasi))->group(function () {
        Route::post('/nomor-wali/{wali}/setujui', [SantriController::class, 'teleponSetujui'])->name('wali.telepon.setujui');
        Route::post('/nomor-wali/{wali}/tolak', [SantriController::class, 'teleponTolak'])->name('wali.telepon.tolak');
    });

    Route::middleware($izin(Izin::PendaftaranProses))->prefix('pendaftaran')->name('pendaftaran.')->group(function () {
        Route::get('/', [PendaftaranController::class, 'index'])->name('index');
        Route::post('/', [PendaftaranController::class, 'store'])->name('store');
        Route::post('/{pendaftaran}/lunas', [PendaftaranController::class, 'lunas'])->name('lunas');
        Route::post('/{pendaftaran}/terima', [PendaftaranController::class, 'terima'])->name('terima');
        Route::post('/{pendaftaran}/tolak', [PendaftaranController::class, 'tolak'])->name('tolak');
    });

    // Membuka file raport: staf (raport.lihat_semua) atau wali untuk anaknya bila tidak tertahan (RaportPolicy).
    Route::get('/raport/{raport}/lihat', [RaportController::class, 'lihat'])->name('raport.lihat');
    Route::middleware($izin(Izin::RaportUnggah))->prefix('raport')->name('raport.')->group(function () {
        Route::get('/', [RaportController::class, 'index'])->name('index');
        Route::post('/santri/{santri}', [RaportController::class, 'unggah'])->name('unggah');
        Route::post('/{raport}/terbitkan', [RaportController::class, 'terbitkan'])->name('terbitkan');
    });
    Route::post('/raport/santri/{santri}/dispensasi', [RaportController::class, 'dispensasi'])->middleware($izin(Izin::KeringananAjukan))->name('raport.dispensasi');

    Route::middleware($izin(Izin::TarifKelola))->prefix('tarif')->name('tarif.')->group(function () {
        Route::get('/', [TarifController::class, 'index'])->name('index');
        Route::post('/', [TarifController::class, 'store'])->name('store');
        Route::delete('/{tarif}', [TarifController::class, 'destroy'])->name('destroy');
        Route::post('/salin/{ta}', [TarifController::class, 'salin'])->name('salin');
    });

    Route::middleware($izin(Izin::PengecualianKelola))->prefix('potongan')->name('potongan.')->group(function () {
        Route::get('/', [PotonganController::class, 'index'])->name('index');
        Route::post('/kecualikan', [PotonganController::class, 'kecualikan'])->name('kecualikan');
        Route::post('/jeda', [PotonganController::class, 'jeda'])->name('jeda');
        Route::post('/{pengecualian}/akhiri', [PotonganController::class, 'akhiri'])->name('akhiri');
        Route::post('/pemicu/{jenis}', [PotonganController::class, 'pemicu'])->name('pemicu');
    });

    Route::middleware($izin(Izin::PeriodeKelola))->prefix('periode')->name('periode.')->group(function () {
        Route::get('/', [PeriodeController::class, 'index'])->name('index');
        Route::post('/semester/{semester}/aktifkan', [PeriodeController::class, 'aktifkan'])->name('aktifkan');
        Route::post('/siapkan', [PeriodeController::class, 'siapkan'])->name('siapkan');
        Route::post('/kelas', [PeriodeController::class, 'kelasStore'])->name('kelas.store');
        Route::post('/kelas/{kelas}/aktif', [PeriodeController::class, 'kelasToggle'])->name('kelas.toggle');
    });

    Route::middleware($izin(Izin::PeriodeKelola))->prefix('kalender')->name('kalender.')->group(function () {
        Route::get('/', [KalenderController::class, 'index'])->name('index');
        Route::post('/', [KalenderController::class, 'store'])->name('store');
        Route::put('/{kalender}', [KalenderController::class, 'update'])->name('update');
        Route::delete('/{kalender}', [KalenderController::class, 'destroy'])->name('destroy');
    });

    Route::middleware($izin(Izin::SantriKelola))->prefix('kenaikan-kelas')->name('kenaikan.')->group(function () {
        Route::get('/', [KenaikanController::class, 'index'])->name('index');
        Route::post('/', [KenaikanController::class, 'store'])->name('store');
    });

    // Layar Pengguna: tab Staf (pengguna.kelola) dan tab Wali santri (juga untuk akun_wali.reset).
    Route::get('/pengguna', [PenggunaController::class, 'index'])->name('pengguna.index')
        ->middleware('permission:'.Izin::PenggunaKelola->value.'|'.Izin::AkunWaliReset->value);
    Route::middleware($izin(Izin::AkunWaliReset))->prefix('pengguna/wali')->name('pengguna.wali.')->group(function () {
        Route::post('/{user}/reset', [PenggunaController::class, 'waliReset'])->name('reset');
        Route::post('/{user}/aktif', [PenggunaController::class, 'waliAktif'])->name('aktif');
    });
    Route::middleware($izin(Izin::PenggunaKelola))->prefix('pengguna')->name('pengguna.')->group(function () {
        Route::post('/', [PenggunaController::class, 'store'])->name('store');
        Route::get('/{user}/ubah', [PenggunaController::class, 'edit'])->name('edit');
        Route::put('/{user}', [PenggunaController::class, 'update'])->name('update');
        Route::post('/{user}/password', [PenggunaController::class, 'password'])->name('password');
        Route::post('/{user}/peran', [PenggunaController::class, 'peran'])->name('peran');
        Route::post('/{user}/reset', [PenggunaController::class, 'reset'])->name('reset');
        Route::post('/{user}/aktif', [PenggunaController::class, 'aktif'])->name('aktif');
    });

    Route::middleware($izin(Izin::HakAksesKelola))->prefix('hak-akses')->name('hakakses.')->group(function () {
        Route::get('/', [HakAksesController::class, 'index'])->name('index');
        Route::post('/', [HakAksesController::class, 'update'])->name('update');
    });

    Route::middleware($izin(Izin::PengeluaranCatat))->prefix('pengeluaran')->name('pengeluaran.')->group(function () {
        Route::get('/', [PengeluaranController::class, 'index'])->name('index');
        Route::post('/', [PengeluaranController::class, 'store'])->name('store');
        Route::delete('/{pengeluaran}', [PengeluaranController::class, 'destroy'])->name('destroy');
        Route::get('/{pengeluaran}/bukti', [PengeluaranController::class, 'bukti'])->name('bukti');
    });

    Route::middleware($izin(Izin::GajiKelola))->prefix('gaji')->name('gaji.')->group(function () {
        Route::get('/', [GajiController::class, 'index'])->name('index');
        Route::post('/', [GajiController::class, 'store'])->name('store');
        Route::get('/pegawai', [GajiController::class, 'pegawai'])->name('pegawai');
        Route::post('/pegawai', [GajiController::class, 'simpanPegawai'])->name('pegawai.store');
        Route::post('/pegawai/impor', [GajiController::class, 'imporPegawai'])->name('pegawai.impor');
        Route::get('/pegawai/{pegawai}/ubah', [GajiController::class, 'ubahPegawai'])->name('pegawai.edit');
        Route::put('/pegawai/{pegawai}', [GajiController::class, 'perbaruiPegawai'])->name('pegawai.update');
        Route::get('/{penggajian}', [GajiController::class, 'show'])->name('show');
        Route::put('/{penggajian}', [GajiController::class, 'update'])->name('update');
        Route::delete('/{penggajian}', [GajiController::class, 'destroy'])->name('destroy');
        Route::get('/{penggajian}/unduh', [GajiController::class, 'unduh'])->name('unduh');
        Route::post('/{penggajian}/final', [GajiController::class, 'finalkan'])->name('final');
    });

    Route::middleware($izin(Izin::MasterKeuanganKelola))->prefix('master-keuangan')->name('masterkeu.')->group(function () {
        Route::get('/', [MasterKeuanganController::class, 'index'])->name('index');
        Route::post('/{jenis}', [MasterKeuanganController::class, 'store'])->whereIn('jenis', ['rekening', 'akun', 'pengusul'])->name('store');
        Route::put('/{jenis}/{id}', [MasterKeuanganController::class, 'update'])->whereIn('jenis', ['rekening', 'akun', 'pengusul'])->name('update');
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

    // Modul guru: hanya kelas & mapel yang ditugaskan (App\Support\KonteksGuru). Rekap juga untuk nilai.lihat_semua.
    Route::middleware($izin(Izin::GuruMengajar))->prefix('guru')->name('guru.')->group(function () {
        Route::get('/', Guru\DashboardController::class)->name('dashboard');
        Route::get('/catatan-harian', [Guru\CatatanHarianController::class, 'index'])->name('catatan.index');
        Route::post('/catatan-harian', [Guru\CatatanHarianController::class, 'store'])->name('catatan.store');
        Route::put('/catatan-harian/{catatan}', [Guru\CatatanHarianController::class, 'update'])->name('catatan.update');
        Route::delete('/catatan-harian/{catatan}', [Guru\CatatanHarianController::class, 'destroy'])->name('catatan.destroy');
        Route::get('/soal', [Guru\SoalController::class, 'index'])->name('soal.index');
        Route::get('/soal/cetak', [Guru\SoalController::class, 'cetak'])->name('soal.cetak');
        Route::post('/soal', [Guru\SoalController::class, 'store'])->name('soal.store');
        Route::put('/soal/{soal}', [Guru\SoalController::class, 'update'])->name('soal.update');
        Route::delete('/soal/{soal}', [Guru\SoalController::class, 'destroy'])->name('soal.destroy');
        Route::get('/nilai', [Guru\NilaiController::class, 'index'])->name('nilai.index');
        Route::post('/nilai', [Guru\NilaiController::class, 'simpan'])->name('nilai.simpan');
    });
    Route::middleware('permission:'.Izin::GuruMengajar->value.'|'.Izin::NilaiLihatSemua->value)->prefix('guru/rekap-nilai')->name('guru.rekap.')->group(function () {
        Route::get('/', [Guru\RekapController::class, 'index'])->name('index');
        Route::get('/unduh', [Guru\RekapController::class, 'unduh'])->name('unduh');
    });

    Route::middleware($izin(Izin::MapelKelola))->prefix('mapel')->name('mapel.')->group(function () {
        Route::get('/', [MapelController::class, 'index'])->name('index');
        Route::post('/', [MapelController::class, 'store'])->name('store');
        Route::put('/{mapel}', [MapelController::class, 'update'])->name('update');
        Route::post('/{mapel}/aktif', [MapelController::class, 'toggle'])->name('toggle');
        Route::post('/penugasan', [MapelController::class, 'tugaskan'])->name('tugaskan');
        Route::delete('/penugasan/{tugas}', [MapelController::class, 'lepas'])->name('lepas');
        Route::post('/penugasan/salin/{ta}', [MapelController::class, 'salin'])->name('salin');
    });

    Route::middleware($izin(Izin::MigrasiJalankan))->prefix('sinkronisasi')->name('sinkronisasi.')->group(function () {
        Route::get('/', [SinkronisasiController::class, 'index'])->name('index');
        Route::post('/', [SinkronisasiController::class, 'store'])->name('store');
        Route::get('/{run}', [SinkronisasiController::class, 'show'])->name('show');
        Route::post('/{run}/batal', [SinkronisasiController::class, 'batal'])->name('batal');
    });

});

// Webhook gateway WhatsApp: tanpa login, dikecualikan dari CSRF (bootstrap/app.php).
Route::prefix('webhook/wa')->group(function () {
    Route::get('/meta', [WebhookWaController::class, 'verifikasiMeta']);
    Route::post('/meta', [WebhookWaController::class, 'meta']);
    Route::post('/ngirimwa', [WebhookWaController::class, 'ngirimwa']);
});
