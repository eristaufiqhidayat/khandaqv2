<?php

namespace App\Enums;

/** Nama izin spatie/laravel-permission. Lihat RolePermissionSeeder untuk pembagian ke peran. */
enum Izin: string
{
    case SetoranCatat = 'setoran.catat';
    case SetoranVerifikasi = 'setoran.verifikasi';
    case PenarikanCatat = 'penarikan.catat';
    case TagihanKelola = 'tagihan.kelola';
    case TarifKelola = 'tarif.kelola';
    case KeringananAjukan = 'keringanan.ajukan';   // diskon DSB/DU & dispensasi raport
    case BeasiswaAjukan = 'beasiswa.ajukan';       // input beasiswa SPP (Admin)
    case KeringananSetujui = 'keringanan.setujui';   // hanya Keuangan (level manager)
    case PengecualianKelola = 'pengecualian.kelola';
    case BankImpor = 'bank.impor';
    case PengeluaranCatat = 'pengeluaran.catat';
    case TutupBuku = 'tutup_buku';
    case TunggakanPutuskan = 'tunggakan.putuskan';   // keputusan tahap 3 bulan
    case RaportUnggah = 'raport.unggah';
    case RaportLihatSemua = 'raport.lihat_semua';
    case LaporanLihat = 'laporan.lihat';

    // Data master
    case SantriKelola = 'santri.kelola';
    case PendaftaranProses = 'pendaftaran.proses';  // terima/tolak pendaftaran santri baru (Admin Office)
    case DataWaliVerifikasi = 'data_wali.verifikasi'; // verifikasi perubahan nomor WhatsApp wali (Admin Office)             // biodata, status, akun wali, penempatan & kenaikan kelas (Admin Office)
    case KelasKelola = 'kelas.kelola';               // daftar kelas (Admin)
    case PeriodeKelola = 'periode.kelola';           // aktifkan tahun ajaran/semester, tanggal PTS/PAS (Admin)
    case WaSiaran = 'wa.siaran';                     // kirim siaran WhatsApp ke wali (Admin Office)
    case MigrasiJalankan = 'migrasi.jalankan';       // salin ulang data dari aplikasi lama (Admin, hanya masa paralel)
    case HakAksesKelola = 'hak_akses.kelola';         // ubah izin tiap peran (Admin; tidak bisa dicabut dari Admin)
    case PenggunaKelola = 'pengguna.kelola';         // buat akun staf, atur peran, reset password staf (Admin)
    case AkunWaliReset = 'akun_wali.reset';           // reset password & nonaktifkan akun wali (Admin Office)
    case MasterKeuanganKelola = 'master_keuangan.kelola'; // rekening, akun biaya, pengusul (Keuangan)

    /** Label untuk layar Hak akses. */
    public function label(): string
    {
        return match ($this) {
            self::SetoranCatat => 'Kasir: setor tunai & catat transfer',
            self::SetoranVerifikasi => 'Verifikasi setoran & mutasi BSI',
            self::PenarikanCatat => 'Tarik uang saku',
            self::TagihanKelola => 'Bayar tagihan dari saldo',
            self::TarifKelola => 'Tarif',
            self::KeringananAjukan => 'Ajukan diskon DSB/DU & dispensasi raport',
            self::BeasiswaAjukan => 'Input beasiswa SPP',
            self::KeringananSetujui => 'Setujui beasiswa, diskon, dispensasi',
            self::PengecualianKelola => 'Potongan otomatis & pengecualian',
            self::BankImpor => 'Impor mutasi BSI',
            self::PengeluaranCatat => 'Catat pengeluaran per dana',
            self::TutupBuku => 'Tutup buku bulanan',
            self::TunggakanPutuskan => 'Keputusan tunggakan 3 bulan',
            self::RaportUnggah => 'Unggah raport',
            self::RaportLihatSemua => 'Lihat semua raport',
            self::LaporanLihat => 'Laporan & ringkasan keuangan',
            self::SantriKelola => 'Data santri, wali & kenaikan kelas',
            self::PendaftaranProses => 'Pendaftaran santri baru',
            self::DataWaliVerifikasi => 'Verifikasi nomor WhatsApp wali',
            self::KelasKelola => 'Daftar kelas',
            self::PeriodeKelola => 'Tahun ajaran & semester',
            self::HakAksesKelola => 'Hak akses',
            self::WaSiaran => 'Siaran WhatsApp ke wali',
            self::MigrasiJalankan => 'Sinkronisasi data lama',
            self::PenggunaKelola => 'Pengguna & peran',
            self::AkunWaliReset => 'Reset sandi & nonaktifkan akun wali',
            self::MasterKeuanganKelola => 'Rekening & akun biaya',
        };
    }
}
