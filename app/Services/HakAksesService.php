<?php

namespace App\Services;

use App\Contracts\PenyimpanIzinPeran;
use App\Enums\Izin;
use App\Exceptions\AturanDilanggar;
use App\Models\User;
use App\Support\Otorisasi;

/**
 * Modul "Hak akses": Admin memindahkan izin (dan menu yang mengikutinya) antar peran,
 * misalnya menu Tarif dari Admin ke Keuangan, tanpa mengubah kode.
 *
 * Pengaman:
 *  - Peran admin selalu memegang hak_akses.kelola & pengguna.kelola (tidak bisa terkunci keluar).
 *  - Peran wali_santri tidak bisa diberi izin staf (portal wali dijaga policy).
 *  - Setiap izin harus tetap dipegang minimal satu peran staf.
 *  - Kombinasi sensitif (mengajukan & menyetujui di peran yang sama) diizinkan tetapi diberi peringatan;
 *    pada tingkat orang, pengaju tetap tidak bisa menyetujui pengajuannya sendiri.
 */
class HakAksesService
{
    private const TERKUNCI_ADMIN = [Izin::HakAksesKelola, Izin::PenggunaKelola];

    private const PASANGAN_SENSITIF = [
        [Izin::KeringananAjukan, Izin::KeringananSetujui],
        [Izin::SetoranCatat, Izin::TutupBuku],
    ];

    public function __construct(private PenyimpanIzinPeran $penyimpan) {}

    /** @return array<string, list<string>> matriks peran => izin (untuk layar) */
    public function matriks(): array
    {
        $hasil = [];
        foreach ($this->penyimpan->daftarPeran() as $peran) {
            $hasil[$peran] = $this->penyimpan->izinPeran($peran);
        }

        return $hasil;
    }

    /** @return list<string> peringatan (kosong bila tidak ada) */
    public function atur(string $peran, Izin $izin, bool $beri, User $admin): array
    {
        Otorisasi::pastikan($admin, Izin::HakAksesKelola);
        if ($peran === 'wali_santri') {
            throw new AturanDilanggar('Peran wali santri tidak bisa diberi izin staf.');
        }
        if (! $beri && $peran === 'admin' && in_array($izin, self::TERKUNCI_ADMIN, true)) {
            throw new AturanDilanggar('Izin ini tidak bisa dicabut dari Admin, agar Admin tidak terkunci keluar.');
        }

        if ($beri) {
            $this->penyimpan->beri($peran, $izin->value);
        } else {
            $pemegang = array_filter(
                $this->matriks(),
                fn (array $daftar, string $p) => $p !== 'wali_santri' && $p !== $peran && in_array($izin->value, $daftar, true),
                ARRAY_FILTER_USE_BOTH,
            );
            if ($pemegang === []) {
                throw new AturanDilanggar("Izin \"{$izin->label()}\" harus tetap dipegang minimal satu peran. Berikan ke peran lain dulu.");
            }
            $this->penyimpan->cabut($peran, $izin->value);
        }

        return $this->peringatan($peran);
    }

    /** Pindahkan izin dari satu peran ke peran lain (mis. Tarif: admin -> keuangan). */
    public function pindahkan(Izin $izin, string $dari, string $ke, User $admin): array
    {
        $w = $this->atur($ke, $izin, true, $admin);

        return array_values(array_unique([...$w, ...$this->atur($dari, $izin, false, $admin)]));
    }

    /** @return list<string> */
    private function peringatan(string $peran): array
    {
        $punya = $this->penyimpan->izinPeran($peran);
        $w = [];
        foreach (self::PASANGAN_SENSITIF as [$a, $b]) {
            if (in_array($a->value, $punya, true) && in_array($b->value, $punya, true)) {
                $w[] = "Peran {$peran} kini bisa \"{$a->label()}\" sekaligus \"{$b->label()}\". Pastikan dipegang orang yang berbeda.";
            }
        }

        return $w;
    }
}
