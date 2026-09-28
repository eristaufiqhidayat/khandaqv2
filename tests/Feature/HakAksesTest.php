<?php

namespace Tests\Feature;

use App\Contracts\PenyimpanIzinPeran;
use App\Enums\Izin;
use App\Exceptions\AturanDilanggar;
use App\Services\HakAksesService;
use Tests\KhandaqTestCase;

class HakAksesTest extends KhandaqTestCase
{
    private function penyimpan(): PenyimpanIzinPeran
    {
        return new class implements PenyimpanIzinPeran {
            public array $m = [
                'admin' => ['tarif.kelola', 'hak_akses.kelola', 'pengguna.kelola', 'keringanan.ajukan'],
                'admin_office' => ['setoran.catat', 'keringanan.ajukan'],
                'keuangan' => ['keringanan.setujui', 'tutup_buku'],
                'wali_santri' => [],
            ];
            public function izinPeran(string $peran): array { return $this->m[$peran]; }
            public function beri(string $peran, string $izin): void { $this->m[$peran] = array_values(array_unique([...$this->m[$peran], $izin])); }
            public function cabut(string $peran, string $izin): void { $this->m[$peran] = array_values(array_diff($this->m[$peran], [$izin])); }
            public function daftarPeran(): array { return array_keys($this->m); }
        };
    }

    public function test_admin_memindahkan_menu_tarif_ke_keuangan(): void
    {
        $p = $this->penyimpan();
        $svc = new HakAksesService($p);
        $admin = $this->beriIzin($this->buatUser('Admin'), Izin::HakAksesKelola);
        $svc->pindahkan(Izin::TarifKelola, 'admin', 'keuangan', $admin);
        $this->assertContains('tarif.kelola', $p->m['keuangan']);
        $this->assertNotContains('tarif.kelola', $p->m['admin']);
    }

    public function test_pengaman_hak_akses(): void
    {
        $p = $this->penyimpan();
        $svc = new HakAksesService($p);
        $admin = $this->beriIzin($this->buatUser('Admin'), Izin::HakAksesKelola);

        foreach ([
            fn () => $svc->atur('admin', Izin::HakAksesKelola, false, $admin),          // admin terkunci keluar
            fn () => $svc->atur('wali_santri', Izin::TarifKelola, true, $admin),       // wali diberi izin staf
            fn () => $svc->atur('keuangan', Izin::TutupBuku, false, $admin),           // izin jadi yatim
            fn () => $svc->atur('keuangan', Izin::TarifKelola, true, $this->buatUser('TU')), // bukan admin
        ] as $i => $aksi) {
            try {
                $aksi();
                $this->fail("aksi #{$i} seharusnya ditolak");
            } catch (AturanDilanggar) {
            }
        }

        $w = $svc->atur('admin_office', Izin::KeringananSetujui, true, $admin);
        $this->assertNotEmpty($w, 'ajukan + setujui di satu peran diberi peringatan');
    }
}
