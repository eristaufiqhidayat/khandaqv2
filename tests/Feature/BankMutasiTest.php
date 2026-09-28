<?php

namespace Tests\Feature;

use App\Enums\StatusBankMutasi;
use App\Models\BankMutasi;
use App\Models\Rekening;
use Tests\KhandaqTestCase;

class BankMutasiTest extends KhandaqTestCase
{
    public function test_impor_csv_bsi_mencocokkan_setoran_dengan_kode_unik(): void
    {
        $a = $this->santri('3 PUTRA', '123');
        $b = $this->santri('2 PUTRA', '456');
        $wali = $this->wali($a);
        $this->tabungan()->catatSetoran($a, 2_315_123, $this->tgl('2026-02-04 10:00'), true, $wali);

        $csv = tempnam(sys_get_temp_dir(), 'bsi');
        file_put_contents($csv, implode("\n", [
            'tanggal,no_referensi,deskripsi,debet,kredit,saldo',
            '05/02/2026 08:15,FT001,TRF DARI WALI A,0,2315123,10000000',
            '05/02/2026 09:00,FT002,SPP Feb KHQ 456,0,1500000,11500000',
            '05/02/2026 10:00,FT003,Biaya admin,6500,0,11493500',
        ]));
        $keu = $this->keuangan();
        $impor = $this->matcher()->imporCsv(Rekening::kode('BSI'), $csv, $keu);

        $this->assertSame(3, $impor->jumlah_baris);
        $this->assertSame(1, $impor->jumlah_cocok);
        $this->assertSame(2_315_123 - 15_000, $a->saldo(), 'setoran A terverifikasi otomatis (infak terpotong)');

        $ditinjau = BankMutasi::where('no_referensi', 'FT002')->first();
        $this->assertSame(StatusBankMutasi::Ditinjau, $ditinjau->status);
        $this->assertSame($b->id, $ditinjau->santri_id_terdeteksi);
        $this->assertSame(StatusBankMutasi::Diabaikan, BankMutasi::where('no_referensi', 'FT003')->first()->status);

        $this->matcher()->terimaSebagaiSetoran($ditinjau, $b, $this->adminOffice());
        $this->assertSame(1_500_000 - 15_000, $b->saldo());

        $ulang = $this->matcher()->imporCsv(Rekening::kode('BSI'), $csv, $keu);
        $this->assertSame(0, $ulang->jumlah_baris, 'file yang sama tidak diimpor dua kali');
    }
}
