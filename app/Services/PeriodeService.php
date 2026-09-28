<?php

namespace App\Services;

use App\Enums\Izin;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Support\Otorisasi;
use Illuminate\Support\Facades\DB;

/**
 * Tahun ajaran & semester dibuat otomatis dari tanggal (Juli-Juni).
 * Admin (izin periode.kelola) hanya mengaktifkan periode berjalan.
 */
class PeriodeService
{
    public function aktifkan(Semester $semester, User $admin): Semester
    {
        Otorisasi::pastikan($admin, Izin::PeriodeKelola);

        return DB::transaction(function () use ($semester) {
            TahunAjaran::query()->update(['aktif' => false]);
            Semester::query()->update(['aktif' => false]);
            $semester->update(['aktif' => true]);
            $semester->tahunAjaran->update(['aktif' => true]);

            return $semester->refresh();
        });
    }
}
