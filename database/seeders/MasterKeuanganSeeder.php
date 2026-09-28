<?php

namespace Database\Seeders;

use App\Enums\Frekuensi;
use App\Models\Dana;
use App\Models\JenisTagihan;
use App\Models\Rekening;
use Illuminate\Database\Seeder;

/**
 * Data master yang dipakai kode. Besaran rupiah TIDAK di sini: diatur di menu Tarif
 * per tahun ajaran sesuai kebijakan (lihat TarifContohSeeder untuk nilai saat ini).
 */
class MasterKeuanganSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'SPP' => 'Dana SPP', 'DSB' => 'Dana Siswa Baru', 'DU' => 'Dana Daftar Ulang',
            'PTS' => 'Dana Penilaian Tengah Semester', 'PAS' => 'Dana Penilaian Akhir Semester',
            'LAUNDRY' => 'Dana Laundry', 'KESEHATAN' => 'Dana Kesehatan', 'INFAK' => 'Infak',
            'KEGIATAN' => 'Dana Kegiatan (rihlah, baksos, lomba)', 'BUKU' => 'Dana Buku & Seragam',
            'FORMULIR' => 'Dana Formulir PSB', 'UMUM' => 'Operasional Umum',
        ] as $kode => $nama) {
            Dana::updateOrCreate(['kode' => $kode], ['nama' => $nama]);
        }

        Rekening::updateOrCreate(['kode' => 'KAS'], ['nama' => 'Kas Tunai Kantor', 'jenis' => 'kas']);
        Rekening::updateOrCreate(['kode' => 'BSI'], ['nama' => 'Rekening BSI Pondok', 'jenis' => 'bank', 'bank' => 'Bank Syariah Indonesia']);

        // kode => [nama, dana, frekuensi, potong_otomatis, wajib_lunas_raport, boleh_dicicil, dihitung_tunggakan, urutan]
        $jenis = [
            'INFAK' => ['Infak', 'INFAK', Frekuensi::PerSetoran, true, false, false, false, 1],
            'SPP' => ['SPP', 'SPP', Frekuensi::Bulanan, true, true, false, true, 10],
            'LAUNDRY' => ['Laundry', 'LAUNDRY', Frekuensi::Bulanan, true, false, false, false, 20],
            'KESEHATAN' => ['Kesehatan', 'KESEHATAN', Frekuensi::Bulanan, true, false, false, false, 30],
            'PTS' => ['Penilaian Tengah Semester', 'PTS', Frekuensi::PerSemester, false, false, false, false, 40],
            'PAS' => ['Penilaian Akhir Semester', 'PAS', Frekuensi::PerSemester, false, false, false, false, 41],
            'DU' => ['Daftar Ulang', 'DU', Frekuensi::Tahunan, false, false, true, false, 50],
            'DSB' => ['Dana Siswa Baru', 'DSB', Frekuensi::Sekali, false, false, true, false, 51],
            'BUKU' => ['Buku & Seragam', 'BUKU', Frekuensi::Insidental, false, false, true, false, 60],
            'KEGIATAN' => ['Kegiatan', 'KEGIATAN', Frekuensi::Insidental, false, false, true, false, 70],
        ];
        foreach ($jenis as $kode => [$nama, $dana, $frek, $otomatis, $raport, $cicil, $tunggakan, $urutan]) {
            JenisTagihan::updateOrCreate(['kode' => $kode], [
                'nama' => $nama, 'dana_id' => Dana::kode($dana)->id, 'frekuensi' => $frek,
                'potong_otomatis' => $otomatis, 'wajib_lunas_untuk_raport' => $raport,
                'boleh_dicicil' => $cicil, 'dihitung_tunggakan' => $tunggakan, 'urutan_alokasi' => $urutan,
            ]);
        }
    }
}
