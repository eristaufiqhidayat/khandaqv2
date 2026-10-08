<?php

namespace App\Support;

use App\Enums\Izin;
use App\Enums\StatusSantri;
use App\Models\GuruMengajar;
use App\Models\Santri;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Pilihan tahun ajaran, semester, kelas & mapel di layar guru.
 * Guru hanya melihat kelas & mapel yang ditugaskan kepadanya; pemegang nilai.lihat_semua (Rekap) melihat semua penugasan.
 * Kelas dan santri memakai data yang sudah ada (kelas, santri_kelas per tahun ajaran).
 */
final class KonteksGuru
{
    /**
     * @param  Collection<int, TahunAjaran>  $daftarTa
     * @param  Collection<int, GuruMengajar>  $penugasan  unik per kelas+mapel
     */
    private function __construct(
        public readonly TahunAjaran $ta,
        public readonly Semester $semester,
        public readonly Collection $daftarTa,
        public readonly Collection $penugasan,
        public readonly ?GuruMengajar $pilih,
    ) {}

    public static function dari(Request $request, bool $semua = false): self
    {
        $daftarTa = TahunAjaran::with(['semester' => fn ($q) => $q->orderBy('nomor')])->orderByDesc('tahun_mulai')->limit(6)->get();
        $ta = $daftarTa->firstWhere('id', (int) $request->query('ta'))
            ?? TahunAjaran::aktif()?->load('semester')
            ?? TahunAjaran::untukTanggal(CarbonImmutable::now())->load('semester');
        if (! $daftarTa->contains('id', $ta->id)) {
            $daftarTa->prepend($ta);
        }

        $semester = $ta->semester->firstWhere('id', (int) $request->query('semester'))
            ?? $ta->semester->firstWhere('aktif', true)
            ?? $ta->semester->firstWhere('nomor', CarbonImmutable::now()->month >= 7 ? 1 : 2)
            ?? $ta->semester->first();

        $penugasan = self::penugasan($request->user(), $ta, $semua);
        $pilih = $penugasan->first(fn (GuruMengajar $g) => $g->kelas_id === (int) $request->query('kelas') && $g->mapel_id === (int) $request->query('mapel'))
            ?? $penugasan->firstWhere('kelas_id', (int) $request->query('kelas'))
            ?? $penugasan->first();

        return new self($ta, $semester, $daftarTa, $penugasan, $pilih);
    }

    /** @return Collection<int, GuruMengajar> */
    public static function penugasan(User $user, TahunAjaran $ta, bool $semua = false): Collection
    {
        $lihatSemua = $semua && $user->hasPermissionTo(Izin::NilaiLihatSemua->value);

        return GuruMengajar::with(['kelas', 'mapel', 'guru'])
            ->where('tahun_ajaran_id', $ta->id)
            ->when(! $lihatSemua, fn ($q) => $q->where('user_id', $user->id))
            ->get()
            ->unique(fn (GuruMengajar $g) => $g->kunci)
            ->sortBy(fn (GuruMengajar $g) => sprintf('%02d|%s|%05d|%s', $g->kelas->tingkat, $g->kelas->nama, $g->mapel->urutan, $g->mapel->nama))
            ->values();
    }

    /** Santri aktif di sebuah kelas pada tahun ajaran (dari riwayat kelas), urut nama. */
    public static function santri(int $kelasId, TahunAjaran $ta): Collection
    {
        return Santri::whereHas('riwayatKelas', fn ($q) => $q->where('kelas_id', $kelasId)->where('tahun_ajaran_id', $ta->id))
            ->where('status', StatusSantri::Aktif->value)
            ->orderBy('nama')
            ->get();
    }

    /** Guru boleh mengisi nilai/catatan untuk kelas+mapel ini di tahun ajaran ini? */
    public static function mengajar(User $user, int $kelasId, int $mapelId, TahunAjaran $ta): bool
    {
        return GuruMengajar::where(['user_id' => $user->id, 'kelas_id' => $kelasId, 'mapel_id' => $mapelId, 'tahun_ajaran_id' => $ta->id])->exists();
    }

    /** Parameter query untuk tautan antar-layar guru. */
    public function query(array $tambah = []): array
    {
        return array_filter(array_merge([
            'ta' => $this->ta->id, 'semester' => $this->semester->id,
            'kelas' => $this->pilih?->kelas_id, 'mapel' => $this->pilih?->mapel_id,
        ], $tambah), fn ($v) => $v !== null);
    }
}
