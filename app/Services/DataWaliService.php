<?php

namespace App\Services;

use App\Enums\Izin;
use App\Exceptions\AturanDilanggar;
use App\Models\Santri;
use App\Models\User;
use App\Support\Otorisasi;
use Illuminate\Support\Facades\DB;

/**
 * Data wali: diisi saat pendaftaran. Wali boleh mengubah alamat & pekerjaan sendiri di portal.
 * Nomor WhatsApp dipakai untuk pemberitahuan resmi (termasuk tahap pemulangan), jadi perubahannya
 * menunggu verifikasi Admin Office.
 */
class DataWaliService
{
    /**
     * Form "Tambah wali" (Admin Office): wali kedua (mis. ibu), pergantian wali, atau wali untuk santri pindahan.
     * Bila nomor WhatsApp sudah terdaftar, anak ditautkan ke akun itu (tidak ada akun ganda).
     *
     * @param  list<Santri>  $anak
     * @return array{wali: User, akun_baru: bool, password_awal: ?string}
     */
    public function tambahAtauTautkan(array $data, iterable $anak, string $hubungan, User $petugas): array
    {
        Otorisasi::pastikan($petugas, Izin::SantriKelola);
        foreach (['name', 'telepon'] as $wajib) {
            if (blank($data[$wajib] ?? null)) {
                throw new AturanDilanggar("Kolom {$wajib} wajib diisi.");
            }
        }
        if (! in_array($hubungan, ['ayah', 'ibu', 'wali'], true)) {
            throw new AturanDilanggar('Hubungan harus ayah, ibu, atau wali.');
        }

        return DB::transaction(function () use ($data, $anak, $hubungan) {
            $telepon = PendaftaranService::normalTelepon($data['telepon']);
            $wali = User::where('telepon', $telepon)->first();
            $password = null;
            if ($wali && ! $wali->hasRole('wali_santri')) {
                throw new AturanDilanggar('Nomor ini dipakai akun staf; gunakan nomor lain untuk akun wali.');
            }
            if (! $wali) {
                $password = AkunService::acak();
                $wali = User::create([
                    'name' => $data['name'], 'username' => $telepon, 'telepon' => $telepon,
                    'email' => ($data['email'] ?? null) ?: $telepon.'@wali.khandaq',
                    'nik' => $data['nik'] ?? null, 'alamat' => $data['alamat'] ?? null, 'pekerjaan' => $data['pekerjaan'] ?? null,
                    'password' => AkunService::hash($password), 'wajib_ganti_password' => true,
                ]);
                $wali->assignRole('wali_santri');
            }
            foreach ($anak as $santri) {
                $wali->anak()->syncWithoutDetaching([$santri->id => ['hubungan' => $hubungan]]);
            }

            return ['wali' => $wali, 'akun_baru' => $password !== null, 'password_awal' => $password];
        });
    }

    /** Lepas tautan wali dari santri (mis. pergantian wali). Akun tetap ada bila masih punya anak lain. */
    public function lepasTautan(User $wali, Santri $santri, User $petugas): void
    {
        Otorisasi::pastikan($petugas, Izin::SantriKelola);
        if ($santri->wali()->count() <= 1) {
            throw new AturanDilanggar('Santri harus punya minimal satu wali; tambahkan wali pengganti dulu.');
        }
        $wali->anak()->detach($santri->id);
        if ($wali->anak()->count() === 0) {
            $wali->update(['aktif' => false]);
        }
    }

    public function ubahProfil(User $wali, array $data): User
    {
        $wali->fill(array_intersect_key($data, array_flip(['alamat', 'pekerjaan', 'email'])));
        if (! empty($data['telepon'])) {
            $baru = PendaftaranService::normalTelepon($data['telepon']);
            if ($baru !== $wali->telepon) {
                $wali->telepon_menunggu = $baru;
            }
        }
        $wali->save();

        return $wali;
    }

    public function setujuiTelepon(User $wali, User $petugas): User
    {
        Otorisasi::pastikan($petugas, Izin::DataWaliVerifikasi);
        if (! $wali->telepon_menunggu) {
            throw new AturanDilanggar('Tidak ada perubahan nomor yang menunggu.');
        }
        if (User::where('telepon', $wali->telepon_menunggu)->whereKeyNot($wali->id)->exists()) {
            throw new AturanDilanggar('Nomor itu sudah dipakai akun wali lain.');
        }
        $wali->update(['telepon' => $wali->telepon_menunggu, 'telepon_menunggu' => null]);

        return $wali;
    }

    public function tolakTelepon(User $wali, User $petugas): User
    {
        Otorisasi::pastikan($petugas, Izin::DataWaliVerifikasi);
        $wali->update(['telepon_menunggu' => null]);

        return $wali;
    }
}
