<?php

namespace Tests\Feature;

use App\Enums\StatusBankMutasi;
use App\Enums\StatusMutasi;
use App\Models\BankMutasi;
use App\Models\Rekening;
use Tests\KhandaqTestCase;

/** Format asli "Account Statement" BSI: Date, FT Number, Description, Currency, Amount, DB, CR, Balance. */
class ImporCsvBsiTest extends KhandaqTestCase
{
    private function csv(array $baris): string
    {
        $f = tempnam(sys_get_temp_dir(), 'bsi');
        $isi = "\u{FEFF}Date,\"FT Number\",\"Description\",\"Currency\",\"Amount\",\"DB\",\"CR\",\"Balance\"\n";
        foreach ($baris as [$tgl, $ft, $ket, $nominal, $arah, $saldo]) {
            $isi .= sprintf("\"%s\",\"%s\",\"%s\",\"IDR\",\"%s\",\"%s\",\"%s\",\"%s\"\n", $tgl, $ft, $ket, number_format($nominal, 2),
                $arah === 'DB' ? 'DB' : '', $arah === 'CR' ? 'CR' : '', number_format($saldo, 2));
        }
        file_put_contents($f, rtrim($isi, "\n").",\n"); // baris terakhir berakhiran koma, seperti file asli

        return $f;
    }

    public function test_format_rekening_koran_bsi_dikenali_dan_biaya_admin_ber_ft_sama_ikut_masuk(): void
    {
        $a = $this->santri('3 PUTRA');
        $this->tabungan()->catatSetoran($a, 2_315_000, $this->tgl('2026-09-04 09:00'), true, $this->wali($a));

        $f = $this->csv([
            ['2026-09-01 07:18:33', 'FT26244KVN30', 'HONOR September Trf Ke - 002 - PENERIMA', 300_000, 'DB', 69_771_326.44],
            ['2026-09-01 07:18:33', 'FT26244KVN30', 'Biaya Pemindahbukuan e-Banking', 6_500, 'DB', 69_764_826.44],
            ['2026-09-02 13:34:00', 'FT26245DHW3C\BNK', 'pindahan Trf Dari - LEMBAGA', 5_000_000, 'CR', 74_764_826.44],
            ['2026-09-04 10:18:10', 'FT2624733S6D', 'BIFAST - TRF Dari - BANK BNI WALI PERTAMA', 2_315_000, 'CR', 77_079_826.44],
        ]);
        $impor = $this->matcher()->imporCsv(Rekening::kode('BSI'), $f, $this->keuangan());

        $this->assertSame(4, $impor->jumlah_baris, 'transfer dan biaya admin dengan FT sama sama-sama masuk');
        $this->assertSame(1, $impor->jumlah_cocok);
        $this->assertSame('2026-09-01', $impor->periode_awal->toDateString());
        $this->assertSame(2, BankMutasi::where('no_referensi', 'FT26244KVN30')->count());
        $this->assertSame(6_500, BankMutasi::where('no_referensi', 'FT26244KVN30')->min('debet'));
        $this->assertTrue(BankMutasi::where('no_referensi', 'FT26245DHW3C')->where('kredit', 5_000_000)->exists(), 'akhiran \BNK dibuang');
        $this->assertSame(69_764_826, BankMutasi::where('debet', 6_500)->value('saldo'));
        $this->assertSame(StatusMutasi::Terverifikasi, $a->mutasi()->first()->status);

        $ulang = $this->matcher()->imporCsv(Rekening::kode('BSI'), $f, $this->keuangan());
        $this->assertSame(0, $ulang->jumlah_baris, 'file yang sama tidak diimpor dua kali');
    }

    public function test_nama_pengirim_dikenali_sebagai_wali(): void
    {
        $a = $this->santri('3 PUTRA');
        $b = $this->santri('2 PUTRA');
        $c = $this->santri('1 PUTRA');
        $waliA = $this->wali($a);
        $waliA->update(['name' => 'Amin Rahmat Permana']);
        $waliB = $this->wali($b);
        $waliB->update(['name' => 'Lies Kurniasih']);
        $this->wali($c)->update(['name' => 'Ida']); // nama terlalu pendek, tidak dipakai
        // Dua wali melapor nominal sama; nama pengirim yang menentukan.
        $this->tabungan()->catatSetoran($a, 2_000_000, $this->tgl('2026-09-02 09:00'), true, $waliA);
        $this->tabungan()->catatSetoran($b, 2_000_000, $this->tgl('2026-09-02 10:00'), true, $waliB);

        $f = $this->csv([
            ['2026-09-02 13:33:08', 'FT26245K6D0S', 'BIFAST - TRF Dari - Bank BCA LIES KURNIASIH', 2_000_000, 'CR', 1],
            ['2026-09-03 18:51:40', 'FT2624656FCN', 'BIFAST - TRF Dari - Bank BCA - AMIN RAHMAT PERMANA', 150_000, 'CR', 2],
            ['2026-09-03 19:51:40', 'FT2624656XXX', 'BIFAST - TRF Dari - Bank BCA IDA', 300_000, 'CR', 3],
        ]);
        $this->matcher()->imporCsv(Rekening::kode('BSI'), $f, $this->keuangan());

        $this->assertSame(StatusMutasi::Terverifikasi, $b->mutasi()->first()->status, 'setoran wali B yang cocok');
        $this->assertSame(StatusMutasi::Pending, $a->mutasi()->first()->status);

        $tanpaLaporan = BankMutasi::where('no_referensi', 'FT2624656FCN')->first();
        $this->assertSame(StatusBankMutasi::Ditinjau, $tanpaLaporan->status);
        $this->assertSame($a->id, $tanpaLaporan->santri_id_terdeteksi);
        $this->assertStringContainsString('Amin Rahmat Permana', $tanpaLaporan->catatan);
        $this->assertNull(BankMutasi::where('no_referensi', 'FT2624656XXX')->value('santri_id_terdeteksi'));
    }

    public function test_notifikasi_email_lalu_csv_tidak_ganda(): void
    {
        Rekening::kode('BSI')->update(['nomor' => '9988893330']);
        BankMutasi::create(['rekening_id' => Rekening::kode('BSI')->id, 'tanggal' => '2026-09-16 07:37:00', 'no_referensi' => 'FT262590CWFJ',
            'deskripsi' => 'Notifikasi email BSI', 'debet' => 280_000, 'kredit' => 0, 'status' => StatusBankMutasi::Diabaikan]);

        $impor = $this->matcher()->imporCsv(Rekening::kode('BSI'), $this->csv([
            ['2026-09-16 07:37:11', 'FT262590CWFJ', 'Biaya Pemindahbukuan e-Banking', 6_500, 'DB', 1],
            ['2026-09-16 07:37:11', 'FT262590CWFJ', 'server seminar Trf Ke - 014 - PENERIMA', 280_000, 'DB', 2],
        ]), $this->keuangan());

        $this->assertSame(1, $impor->jumlah_baris, 'hanya biaya admin yang baru');
        $this->assertSame(2, BankMutasi::where('no_referensi', 'FT262590CWFJ')->count());
    }
}
