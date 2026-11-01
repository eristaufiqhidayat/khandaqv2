<?php

namespace Tests\Feature;

use App\Enums\Izin;
use App\Models\Dana;
use App\Models\JenisTagihan;
use App\Models\User;
use Tests\KhandaqTestCase;

/** Menu Potongan otomatis: pilih uang masuk (kredit) mana saja yang langsung dipotong infak. */
class PemicuInfakTest extends KhandaqTestCase
{
    private function infakDipotong($santri): int
    {
        return (int) $santri->tagihan()->whereHas('jenisTagihan', fn ($q) => $q->where('kode', 'INFAK'))->sum('terbayar');
    }

    private function admin2(): User
    {
        return tap($this->beriIzin($this->buatUser('Admin'), Izin::PengecualianKelola), fn ($u) => $u->update(['wajib_ganti_password' => false]));
    }

    public function test_bawaan_transfer_dan_tunai_dipotong_infak(): void
    {
        $s = $this->santri('3 PUTRA');
        $this->tabungan()->catatSetoran($s, 100_000, $this->tgl('2026-02-05 09:00'), false, $this->adminOffice());
        $m = $this->tabungan()->catatSetoran($s, 200_000, $this->tgl('2026-02-06 09:00'), true, $this->wali($s), 'bukti/a.jpg');
        $this->tabungan()->verifikasi($m, $this->adminOffice());

        $this->assertSame(['setoran_transfer', 'setoran_tunai'], JenisTagihan::kode('INFAK')->pemicuKredit());
        $this->assertSame(30_000, $this->infakDipotong($s));
        $this->assertSame(270_000, $s->saldo());
    }

    public function test_admin_memilih_kredit_pemicu_dan_besar_infak_dari_tarif(): void
    {
        $infak = JenisTagihan::kode('INFAK');
        $this->actingAs($this->admin2());
        $this->get(route('potongan.index'))->assertOk()->assertSee('Infak: uang masuk yang dipotong')->assertSee('Pindahan dana DSB/DU');

        // Hanya transfer + pindahan dana; tunai tidak.
        $this->post(route('potongan.pemicu', $infak), ['pemicu' => ['setoran_transfer', 'transfer_dana']])->assertSessionHas('status');
        $this->assertSame(['setoran_transfer', 'transfer_dana'], $infak->fresh()->pemicuKredit());
        $this->post(route('potongan.pemicu', $infak), ['pemicu' => ['koreksi']])->assertSessionHasErrors('pemicu.0');

        // Besar infak dari menu Tarif: ubah jadi 20.000.
        $infak->tarif()->update(['nominal' => 20_000]);

        $s = $this->santri('3 PUTRA');
        $this->tabungan()->catatSetoran($s, 100_000, $this->tgl('2026-02-05 09:00'), false, $this->adminOffice());
        $this->assertSame(0, $this->infakDipotong($s), 'tunai tidak dipotong');

        $m = $this->tabungan()->catatSetoran($s, 200_000, $this->tgl('2026-02-06 09:00'), true, $this->wali($s), 'bukti/a.jpg');
        $this->tabungan()->verifikasi($m, $this->adminOffice());
        $this->assertSame(20_000, $this->infakDipotong($s), 'transfer dipotong 20.000');

        $this->tabungan()->transferDana($s, Dana::where('kode', 'DSB')->sole(), 50_000, $this->tgl('2026-02-07 09:00'), 'SPP dari DSB', $this->keuangan());
        $this->assertSame(40_000, $this->infakDipotong($s), 'pindahan dana juga dipotong');

        // Semua dicentang lepas: infak tidak dipotong dari kredit apa pun.
        $this->post(route('potongan.pemicu', $infak), [])->assertSessionHas('status');
        $this->assertSame([], $infak->fresh()->pemicuKredit());
    }
}
