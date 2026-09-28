<?php

namespace Database\Seeders;

use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Tarif;
use App\Models\TahunAjaran;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Tarif per 2025/2026 menurut data aplikasi lama (tbl_tarif_spp periode 16 + nilai hardcode).
 * Untuk tahun ajaran baru, salin lalu sesuaikan lewat menu Tarif.
 */
class TarifContohSeeder extends Seeder
{
    public function run(): void
    {
        $ta = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1));
        $mulai = $ta->mulai->toDateString();

        $umum = ['INFAK' => 15000, 'LAUNDRY' => 100000, 'KESEHATAN' => 50000];
        foreach ($umum as $kode => $nominal) {
            Tarif::updateOrCreate(
                ['jenis_tagihan_id' => JenisTagihan::kode($kode)->id, 'tahun_ajaran_id' => $ta->id, 'kelas_id' => null, 'berlaku_mulai' => $mulai],
                ['nominal' => $nominal, 'keterangan' => 'Dari nilai hardcode aplikasi lama'],
            );
        }

        // SPP per kelas (tbl_tarif_spp, periode 15/16).
        $spp = ['1 PUTRA' => 1400000, '1 PUTRI' => 1400000, '2 PUTRA' => 1400000, '2 PUTRI' => 1400000,
            '3 PUTRA' => 1700000, '3 PUTRI' => 1700000, '4' => 1400000, '5' => 1400000, '6' => 1700000];
        foreach ($spp as $namaKelas => $nominal) {
            $kelas = Kelas::firstOrCreate(['nama' => $namaKelas], ['tingkat' => (int) $namaKelas]);
            Tarif::updateOrCreate(
                ['jenis_tagihan_id' => JenisTagihan::kode('SPP')->id, 'tahun_ajaran_id' => $ta->id, 'kelas_id' => $kelas->id, 'berlaku_mulai' => $mulai],
                ['nominal' => $nominal, 'keterangan' => 'tbl_tarif_spp'],
            );
        }
    }
}
