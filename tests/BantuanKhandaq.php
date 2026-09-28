<?php

namespace Tests;

use App\Enums\Izin;
use App\Enums\StatusSantri;
use App\Models\Kelas;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\AksesRaport;
use App\Services\BankMutasiMatcher;
use App\Services\KeringananService;
use App\Services\TabunganService;
use App\Services\TagihanGenerator;
use Carbon\CarbonImmutable;

/** Pembuat data uji yang dipakai bersama oleh versi lab dan versi Laravel. */
trait BantuanKhandaq
{
    protected function adminOffice(): User
    {
        return $this->beriIzin($this->buatUser('Admin Office'), Izin::SetoranCatat, Izin::SetoranVerifikasi, Izin::PenarikanCatat,
            Izin::TagihanKelola, Izin::KeringananAjukan, Izin::RaportUnggah, Izin::RaportLihatSemua);
    }

    protected function keuangan(): User
    {
        return $this->beriIzin($this->buatUser('Manager Keuangan'), Izin::KeringananSetujui, Izin::BankImpor, Izin::SetoranVerifikasi,
            Izin::PengeluaranCatat, Izin::TutupBuku, Izin::TunggakanPutuskan, Izin::LaporanLihat, Izin::RaportLihatSemua);
    }

    protected function admin(): User
    {
        return $this->beriIzin($this->buatUser('Admin'), Izin::TarifKelola, Izin::BeasiswaAjukan, Izin::PengecualianKelola,
            Izin::TagihanKelola, Izin::RaportUnggah, Izin::RaportLihatSemua);
    }

    protected function wali(Santri ...$anak): User
    {
        $u = $this->buatUser('Wali');
        foreach ($anak as $s) {
            $u->anak()->attach($s->id, ['hubungan' => 'wali']);
        }

        return $u;
    }

    protected function santri(string $kelas = '3 PUTRA', ?string $kodeUnik = null, string $masuk = '2023-07-10'): Santri
    {
        static $n = 0;
        $n++;
        $s = Santri::create([
            'nis' => 'NIS'.$n.uniqid(), 'nama' => 'Santri '.$n, 'jenis_kelamin' => 'laki-laki',
            'status' => StatusSantri::Aktif, 'kode_unik' => $kodeUnik, 'tanggal_masuk' => $masuk,
        ]);
        $ta = TahunAjaran::untukTanggal(CarbonImmutable::create(2025, 7, 1));
        $s->riwayatKelas()->create(['kelas_id' => Kelas::where('nama', $kelas)->firstOrFail()->id, 'tahun_ajaran_id' => $ta->id]);

        return $s;
    }

    protected function tabungan(): TabunganService
    {
        return new TabunganService();
    }

    protected function generator(): TagihanGenerator
    {
        return new TagihanGenerator();
    }

    protected function keringananSvc(): KeringananService
    {
        return new KeringananService();
    }

    protected function aksesRaport(): AksesRaport
    {
        return new AksesRaport($this->keringananSvc());
    }

    protected function matcher(): BankMutasiMatcher
    {
        return new BankMutasiMatcher($this->tabungan());
    }

    protected function tgl(string $s): CarbonImmutable
    {
        return CarbonImmutable::parse($s);
    }
}
