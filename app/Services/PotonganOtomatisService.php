<?php

namespace App\Services;

use App\Enums\Izin;
use App\Enums\StatusTagihan;
use App\Exceptions\AturanDilanggar;
use App\Models\JedaPotongan;
use App\Models\JenisTagihan;
use App\Models\PengecualianPotongan;
use App\Models\Santri;
use App\Models\Tagihan;
use App\Models\User;
use App\Support\Otorisasi;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Opsi "tidak dijalankan" untuk potongan otomatis (infak, laundry, kesehatan):
 * per santri (pengecualian) atau massal sementara (jeda). Tagihan yang sudah terbit
 * dalam rentang itu dan belum dibayar sama sekali ikut dibatalkan.
 */
class PotonganOtomatisService
{
    public function kecualikan(Santri $santri, JenisTagihan $jenis, CarbonInterface $mulai, ?CarbonInterface $selesai, string $alasan, User $admin): PengecualianPotongan
    {
        Otorisasi::pastikan($admin, Izin::PengecualianKelola);
        $this->pastikanOtomatis($jenis);

        return DB::transaction(function () use ($santri, $jenis, $mulai, $selesai, $alasan, $admin) {
            $p = PengecualianPotongan::create([
                'santri_id' => $santri->id, 'jenis_tagihan_id' => $jenis->id,
                'mulai' => $mulai, 'selesai' => $selesai, 'alasan' => $alasan, 'dibuat_oleh' => $admin->id,
            ]);
            $this->batalkanTagihanBelumDibayar($jenis, $mulai, $selesai, $santri);

            return $p;
        });
    }

    public function jeda(JenisTagihan $jenis, CarbonInterface $mulai, CarbonInterface $selesai, string $alasan, User $admin): JedaPotongan
    {
        Otorisasi::pastikan($admin, Izin::PengecualianKelola);
        $this->pastikanOtomatis($jenis);

        return DB::transaction(function () use ($jenis, $mulai, $selesai, $alasan, $admin) {
            $j = JedaPotongan::create([
                'jenis_tagihan_id' => $jenis->id, 'mulai' => $mulai, 'selesai' => $selesai,
                'alasan' => $alasan, 'dibuat_oleh' => $admin->id,
            ]);
            $this->batalkanTagihanBelumDibayar($jenis, $mulai, $selesai);

            return $j;
        });
    }

    private function batalkanTagihanBelumDibayar(JenisTagihan $jenis, CarbonInterface $mulai, ?CarbonInterface $selesai, ?Santri $santri = null): void
    {
        Tagihan::where('jenis_tagihan_id', $jenis->id)
            ->where('status', StatusTagihan::Belum->value)
            ->where('terbayar', 0)
            ->whereNotNull('periode')
            ->where('periode', '>=', CarbonImmutable::parse($mulai)->startOfMonth()->toDateString())
            ->when($selesai, fn ($q) => $q->where('periode', '<=', CarbonImmutable::parse($selesai)->toDateString()))
            ->when($santri, fn ($q) => $q->where('santri_id', $santri->id))
            ->update(['status' => StatusTagihan::Dibatalkan->value]);
    }

    private function pastikanOtomatis(JenisTagihan $jenis): void
    {
        if (! $jenis->potong_otomatis) {
            throw new AturanDilanggar('Hanya potongan otomatis (infak, laundry, kesehatan, SPP) yang bisa dikecualikan.');
        }
    }
}
