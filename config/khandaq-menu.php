<?php

use App\Enums\Izin;

/**
 * Menu staf. Menu tampil bila user memiliki izinnya, apa pun perannya.
 * Jadi memindahkan izin di layar Hak akses otomatis memindahkan menunya.
 * Route yang sama dijaga middleware `permission:<izin>`. `izin` boleh berupa daftar: cukup punya salah satunya.
 */
return [
    ['label' => 'Ringkasan keuangan', 'route' => 'laporan.ringkasan', 'izin' => Izin::LaporanLihat],
    ['label' => 'Status pembayaran', 'route' => 'laporan.status', 'izin' => Izin::LaporanLihat],
    ['label' => 'Kasir santri', 'route' => 'kasir.index', 'izin' => Izin::SetoranCatat],
    ['label' => 'Verifikasi setoran', 'route' => 'verifikasi.index', 'izin' => Izin::SetoranVerifikasi],
    ['label' => 'Pendaftaran santri baru', 'route' => 'pendaftaran.index', 'izin' => Izin::PendaftaranProses],
    ['label' => 'Data santri & wali', 'route' => 'santri.index', 'izin' => Izin::SantriKelola],
    ['label' => 'Raport & dispensasi', 'route' => 'raport.index', 'izin' => Izin::RaportUnggah],
    ['label' => 'Persetujuan', 'route' => 'persetujuan.index', 'izin' => Izin::KeringananSetujui],
    ['label' => 'Impor mutasi BSI', 'route' => 'bank.index', 'izin' => Izin::BankImpor],
    ['label' => 'Tunggakan', 'route' => 'tunggakan.index', 'izin' => Izin::TunggakanPutuskan],
    ['label' => 'Pengeluaran', 'route' => 'pengeluaran.index', 'izin' => Izin::PengeluaranCatat],
    ['label' => 'Gaji pegawai', 'route' => 'gaji.index', 'izin' => Izin::GajiKelola],
    ['label' => 'Tutup buku', 'route' => 'tutupbuku.index', 'izin' => Izin::TutupBuku],
    ['label' => 'Tarif', 'route' => 'tarif.index', 'izin' => Izin::TarifKelola],
    ['label' => 'Potongan otomatis', 'route' => 'potongan.index', 'izin' => Izin::PengecualianKelola],
    ['label' => 'Tahun ajaran & kelas', 'route' => 'periode.index', 'izin' => Izin::PeriodeKelola],
    ['label' => 'Kalender akademik', 'route' => 'kalender.index', 'izin' => Izin::PeriodeKelola],
    ['label' => 'Mapel & guru pengajar', 'route' => 'mapel.index', 'izin' => Izin::MapelKelola],
    ['label' => 'Rekening & akun biaya', 'route' => 'masterkeu.index', 'izin' => Izin::MasterKeuanganKelola],
    ['label' => 'Pengguna', 'route' => 'pengguna.index', 'izin' => [Izin::PenggunaKelola, Izin::AkunWaliReset]], // Admin Office: tab Wali saja
    ['label' => 'Siaran WhatsApp', 'route' => 'siaran.index', 'izin' => Izin::WaSiaran],
    ['label' => 'Sinkronisasi data lama', 'route' => 'sinkronisasi.index', 'izin' => Izin::MigrasiJalankan],
    ['label' => 'Hak akses', 'route' => 'hakakses.index', 'izin' => Izin::HakAksesKelola],
    // Modul guru, paling akhir (`grup` tampil sebagai judul kecil di sidebar; menu bergrup diletakkan setelah menu tanpa grup).
    ['label' => 'Dashboard guru', 'route' => 'guru.dashboard', 'izin' => Izin::GuruMengajar, 'grup' => 'Menu guru'],
    ['label' => 'Catatan harian guru', 'route' => 'guru.catatan.index', 'izin' => Izin::GuruMengajar, 'grup' => 'Menu guru'],
    ['label' => 'Soal pilihan ganda', 'route' => 'guru.soal.index', 'izin' => Izin::GuruMengajar, 'grup' => 'Menu guru'],
    ['label' => 'Input nilai siswa', 'route' => 'guru.nilai.index', 'izin' => Izin::GuruMengajar, 'grup' => 'Menu guru'],
    ['label' => 'Rekap nilai', 'route' => 'guru.rekap.index', 'izin' => [Izin::GuruMengajar, Izin::NilaiLihatSemua], 'grup' => 'Menu guru'],
];
