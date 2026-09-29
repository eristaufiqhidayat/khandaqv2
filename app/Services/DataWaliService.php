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
            // Akun lama yang sempat nonaktif (mis. kakak sudah lulus/keluar lalu tautannya dilepas) aktif lagi
            // karena kini punya anak aktif. Password lama tetap berlaku.
            if (! $wali->aktif) {
                $wali->update(['aktif' => true]);
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

    /**
     * Ubah data wali oleh petugas (Admin Office). Nomor WhatsApp langsung berlaku (petugas = pemverifikasi),
     * tetapi ditolak bila sudah dipakai akun lain agar tidak ada dua akun untuk satu orang.
     * Username tidak diubah; wali tetap bisa masuk dengan username lama atau nomor WA barunya.
     *
     * @param  array<int,string>  $hubungan  santri_id => ayah|ibu|wali
     */
    public function ubahOlehPetugas(User $wali, array $data, array $hubungan, User $petugas): User
    {
        if (! $petugas->hasPermissionTo(Izin::SantriKelola->value) && ! $petugas->hasPermissionTo(Izin::AkunWaliReset->value)) {
            throw new AturanDilanggar('Tidak punya izin mengubah data wali.');
        }
        if (! $wali->hasRole('wali_santri')) {
            throw new AturanDilanggar('Akun ini bukan akun wali.');
        }
        if (blank($data['name'] ?? null) || blank($data['telepon'] ?? null)) {
            throw new AturanDilanggar('Nama dan nomor WhatsApp wajib diisi.');
        }
        $telepon = PendaftaranService::normalTelepon($data['telepon']);
        $pemilik = User::where(fn ($q) => $q->where('telepon', $telepon)->orWhere('username', $telepon))->whereKeyNot($wali->id)->first();
        if ($pemilik) {
            throw new AturanDilanggar("Nomor {$telepon} sudah dipakai akun {$pemilik->name}. Bila orangnya sama, tautkan santri ke akun itu lewat Tambah wali.");
        }
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && User::where('email', $email)->whereKeyNot($wali->id)->exists()) {
            throw new AturanDilanggar("Email {$email} sudah dipakai akun lain.");
        }

        return DB::transaction(function () use ($wali, $data, $hubungan, $telepon, $email) {
            $wali->fill([
                'name' => trim($data['name']), 'nik' => ($data['nik'] ?? null) ?: null,
                'alamat' => ($data['alamat'] ?? null) ?: null, 'pekerjaan' => ($data['pekerjaan'] ?? null) ?: null,
            ]);
            if ($email !== '') {
                $wali->email = $email;
            }
            if ($telepon !== $wali->telepon) {
                $wali->telepon = $telepon;
                $wali->telepon_menunggu = null;
            } elseif ($wali->telepon_menunggu === $telepon) {
                $wali->telepon_menunggu = null;
            }
            $wali->save();
            foreach ($hubungan as $santriId => $h) {
                if (in_array($h, ['ayah', 'ibu', 'wali'], true)) {
                    $wali->anak()->updateExistingPivot((int) $santriId, ['hubungan' => $h]);
                }
            }

            return $wali;
        });
    }

    /** Portal wali: alamat, pekerjaan, email langsung tersimpan; nomor WhatsApp baru menunggu verifikasi Admin Office. */
    public function ubahProfil(User $wali, array $data): User
    {
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && User::where('email', $email)->whereKeyNot($wali->id)->exists()) {
            throw new AturanDilanggar("Email {$email} sudah dipakai akun lain.");
        }
        foreach (['alamat', 'pekerjaan'] as $k) {
            if (array_key_exists($k, $data)) {
                $wali->{$k} = trim((string) $data[$k]) ?: null;
            }
        }
        if ($email !== '') {
            $wali->email = $email;
        }
        if (! empty($data['telepon'])) {
            $baru = PendaftaranService::normalTelepon($data['telepon']);
            $wali->telepon_menunggu = $baru !== $wali->telepon ? $baru : null;
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
