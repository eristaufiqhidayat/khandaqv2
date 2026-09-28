<?php

namespace Tests\Feature;

use App\Enums\StatusTagihan;
use App\Models\JenisTagihan;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Services\PotonganOtomatisService;
use Tests\KhandaqTestCase;

class TahunAjaranDanTagihanTest extends KhandaqTestCase
{
    public function test_tahun_ajaran_juli_sampai_juni(): void
    {
        $this->assertSame('2025/2026', TahunAjaran::namaUntuk($this->tgl('2025-07-01')));
        $this->assertSame('2025/2026', TahunAjaran::namaUntuk($this->tgl('2026-06-30')));
        $this->assertSame('2026/2027', TahunAjaran::namaUntuk($this->tgl('2026-07-01')));

        $ta = TahunAjaran::untukTanggal($this->tgl('2026-02-10'));
        $this->assertSame('2026-06-30', $ta->selesai->toDateString());
        $this->assertSame(2, $ta->semesterUntuk($this->tgl('2026-02-10'))->nomor);
    }

    public function test_tagihan_bulanan_spp_laundry_kesehatan_jatuh_tempo_akhir_bulan_dan_idempoten(): void
    {
        $s = $this->santri('3 PUTRA');
        $this->assertSame(3, $this->generator()->bulanan($this->tgl('2026-02-01')));
        $this->assertSame(0, $this->generator()->bulanan($this->tgl('2026-02-15')), 'dijalankan ulang tidak menggandakan');

        $spp = $s->tagihan()->whereHas('jenisTagihan', fn ($q) => $q->where('kode', 'SPP'))->first();
        $this->assertSame(1_700_000, $spp->nominal);
        $this->assertSame('2026-02-28', $spp->jatuh_tempo->toDateString());
        $this->assertEqualsCanonicalizing([1_700_000, 100_000, 50_000], $s->tagihan()->pluck('nominal')->all());
    }

    public function test_beasiswa_disetujui_keuangan_memotong_tagihan_spp(): void
    {
        $s = $this->santri('1 PUTRA');
        $ta = TahunAjaran::untukTanggal($this->tgl('2025-07-01'));
        $admin = $this->admin();
        $k = $this->keringananSvc()->ajukanBeasiswa($s, $ta, 50, null, 'Yatim', $admin);

        $this->generator()->bulanan($this->tgl('2025-08-01'));
        $sppAgu = $this->sppPeriode($s->id, '2025-08-01');
        $this->assertSame(0, $sppAgu->potongan, 'belum disetujui -> belum berlaku');

        $this->keringananSvc()->setujui($k, $this->keuangan());
        $this->assertSame(700_000, $sppAgu->fresh()->potongan, 'tagihan terbuka ikut dipotong');

        $this->generator()->bulanan($this->tgl('2025-09-01'));
        $this->assertSame(700_000, $this->sppPeriode($s->id, '2025-09-01')->netto());
    }

    public function test_opsi_tidak_dijalankan_per_santri_dan_jeda_massal(): void
    {
        $a = $this->santri('2 PUTRA');
        $b = $this->santri('2 PUTRA');
        $laundry = JenisTagihan::kode('LAUNDRY');
        $this->generator()->bulanan($this->tgl('2026-01-01'));

        (new PotonganOtomatisService())->kecualikan($a, $laundry, $this->tgl('2026-01-01'), null, 'Tidak ikut laundry', $this->admin());
        $this->assertSame(StatusTagihan::Dibatalkan, $a->tagihan()->where('jenis_tagihan_id', $laundry->id)->first()->status);

        $this->generator()->bulanan($this->tgl('2026-02-01'));
        $this->assertSame(1, $a->tagihan()->where('jenis_tagihan_id', $laundry->id)->count(), 'Februari tidak ditagih laundry');
        $this->assertSame(2, $b->tagihan()->where('jenis_tagihan_id', $laundry->id)->count());

        (new PotonganOtomatisService())->jeda(JenisTagihan::kode('KESEHATAN'), $this->tgl('2026-03-01'), $this->tgl('2026-03-31'), 'Libur', $this->admin());
        $this->generator()->bulanan($this->tgl('2026-03-01'));
        $this->assertSame(0, Tagihan::whereHas('jenisTagihan', fn ($q) => $q->where('kode', 'KESEHATAN'))->where('periode', '2026-03-01')->count());
    }

    public function test_daftar_ulang_hanya_untuk_santri_lama_dan_dsb_untuk_santri_baru(): void
    {
        $ta = TahunAjaran::untukTanggal($this->tgl('2025-07-01'));
        foreach (['DU' => 3_000_000, 'DSB' => 5_000_000] as $kode => $nominal) {
            \App\Models\Tarif::create(['jenis_tagihan_id' => JenisTagihan::kode($kode)->id, 'tahun_ajaran_id' => $ta->id,
                'berlaku_mulai' => $ta->mulai->toDateString(), 'nominal' => $nominal]);
        }
        $lama = $this->santri('2 PUTRA', masuk: '2024-07-10');
        $baru = $this->santri('1 PUTRA', masuk: '2025-07-12');

        $this->assertSame(1, $this->generator()->daftarUlang($ta));
        $this->assertTrue($lama->tagihan()->whereHas('jenisTagihan', fn ($q) => $q->where('kode', 'DU'))->exists());
        $this->assertSame(5_000_000, $this->generator()->dsb($baru, $ta, $this->tgl('2025-12-31'))->nominal);
        $this->assertSame('DSB', $baru->tagihan()->first()->jenisTagihan->dana->kode, 'DSB & DU dananya terpisah');
    }

    private function sppPeriode(int $santriId, string $periode): Tagihan
    {
        return Tagihan::where('santri_id', $santriId)->where('periode', $periode)
            ->whereHas('jenisTagihan', fn ($q) => $q->where('kode', 'SPP'))->firstOrFail();
    }
}
