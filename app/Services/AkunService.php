<?php

namespace App\Services;

use App\Enums\Izin;
use App\Exceptions\AturanDilanggar;
use App\Models\User;
use App\Support\Otorisasi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Akun & password. Aturan:
 * - Tidak ada petugas yang mengetik atau mengetahui password orang lain. Password awal dan password
 *   reset dibuat acak oleh sistem dan dikirim langsung ke WhatsApp pemilik akun (pemanggil yang mengirim).
 * - Pemilik wajib mengganti password saat login pertama (middleware memeriksa `wajib_ganti_password`).
 * - Akun staf dibuat & di-reset oleh Admin. Akun wali dibuat otomatis saat pendaftaran diterima
 *   (PendaftaranService), di-reset/dinonaktifkan oleh Admin Office.
 * - Username wali = nomor WhatsApp; username staf ditentukan Admin.
 */
class AkunService
{
    public const PANJANG_MINIMAL = 8;

    /** @return array{user: User, password_awal: string} */
    public function buatStaf(string $nama, string $username, string $email, string $telepon, string $peran, User $admin): array
    {
        Otorisasi::pastikan($admin, Izin::PenggunaKelola);
        if (! in_array($peran, ['admin', 'admin_office', 'keuangan'], true)) {
            throw new AturanDilanggar('Peran staf tidak dikenal.');
        }
        $password = self::acak();
        $user = User::create([
            'name' => $nama, 'username' => $username, 'email' => $email, 'telepon' => PendaftaranService::normalTelepon($telepon),
            'password' => self::hash($password), 'wajib_ganti_password' => true,
        ]);
        $user->assignRole($peran);

        return ['user' => $user, 'password_awal' => $password];
    }

    /** Reset: password lama langsung tidak berlaku, password sementara dikirim ke WhatsApp pemilik. */
    public function resetPassword(User $target, User $petugas): string
    {
        $izin = $target->hasRole('wali_santri') ? Izin::AkunWaliReset : Izin::PenggunaKelola;
        Otorisasi::pastikan($petugas, $izin);
        if ($target->id === $petugas->id) {
            throw new AturanDilanggar('Gunakan menu ganti password untuk akun sendiri.');
        }
        $password = self::acak();
        $target->update(['password' => self::hash($password), 'password_lama' => null, 'wajib_ganti_password' => true]);

        return $password;
    }

    /**
     * Petugas menetapkan password baru untuk akun wali (mis. wali lupa password dan WhatsApp belum aktif).
     * Semua sesi aplikasi wali dicabut. Bila $wajibGanti, wali harus mengganti password ini saat masuk.
     */
    public function aturPasswordWali(User $wali, string $baru, bool $wajibGanti, User $petugas): void
    {
        if (! $petugas->hasAnyPermission([Izin::AkunWaliReset->value, Izin::PenggunaKelola->value])) {
            throw new AturanDilanggar('Tidak punya izin mengganti password wali.');
        }
        if (! $wali->hasRole('wali_santri')) {
            throw new AturanDilanggar('Akun ini bukan akun wali.');
        }
        if (mb_strlen($baru) < self::PANJANG_MINIMAL) {
            throw new AturanDilanggar('Password minimal '.self::PANJANG_MINIMAL.' karakter.');
        }
        $wali->update([
            'password' => self::hash($baru), 'password_lama' => null,
            'wajib_ganti_password' => $wajibGanti, 'password_diubah_pada' => CarbonImmutable::now(),
        ]);
        \App\Models\ApiToken::where('user_id', $wali->id)->delete();
    }

    /** Admin menetapkan password akun staf lain (mis. lupa password dan WhatsApp belum aktif). */
    public function aturPasswordStaf(User $staf, string $baru, bool $wajibGanti, User $petugas): void
    {
        Otorisasi::pastikan($petugas, Izin::PenggunaKelola);
        if ($staf->hasRole('wali_santri')) {
            throw new AturanDilanggar('Akun wali diubah dari tab Wali santri.');
        }
        if ($staf->is($petugas)) {
            throw new AturanDilanggar('Gunakan menu Ganti password untuk akun sendiri.');
        }
        if (mb_strlen($baru) < self::PANJANG_MINIMAL) {
            throw new AturanDilanggar('Password minimal '.self::PANJANG_MINIMAL.' karakter.');
        }
        $staf->update([
            'password' => self::hash($baru), 'password_lama' => null,
            'wajib_ganti_password' => $wajibGanti, 'password_diubah_pada' => CarbonImmutable::now(),
        ]);
    }

    /** Cari akun untuk login: username, email, atau nomor WhatsApp (format apa pun). Dipakai web dan API aplikasi. */
    public function cariUntukMasuk(string $masukan): ?User
    {
        $masukan = trim($masukan);
        $telepon = PendaftaranService::normalTelepon($masukan);

        return User::where('username', $masukan)
            ->orWhere('email', \Illuminate\Support\Str::lower($masukan))
            ->when(strlen($telepon) >= 9, fn ($q) => $q->orWhere('telepon', $telepon)->orWhere('username', $telepon))
            ->first();
    }

    public function gantiPassword(User $user, string $lama, string $baru): void
    {
        if (! password_verify($lama, $user->password)) {
            throw new AturanDilanggar('Password lama salah.');
        }
        if (mb_strlen($baru) < self::PANJANG_MINIMAL) {
            throw new AturanDilanggar('Password baru minimal '.self::PANJANG_MINIMAL.' karakter.');
        }
        if ($baru === $lama) {
            throw new AturanDilanggar('Password baru harus berbeda dari password lama.');
        }
        $user->update([
            'password' => self::hash($baru), 'wajib_ganti_password' => false,
            'password_diubah_pada' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Dipakai saat login (Fortify::authenticateUsing / LoginController). Password Laravel dicek dulu; bila belum
     * cocok dan akun membawa hash dari aplikasi lama, password dicek dengan cara Myth/Auth lalu langsung
     * diubah ke hash Laravel, sehingga wali cukup memakai password yang sudah ia kenal.
     */
    public function cocokkanPassword(User $user, string $plain): bool
    {
        if (password_verify($plain, $user->password)) {
            return true;
        }
        if ($user->password_lama && password_verify(base64_encode(hash('sha384', $plain, true)), $user->password_lama)) {
            $user->update(['password' => self::hash($plain), 'password_lama' => null, 'password_diubah_pada' => CarbonImmutable::now(),
                // Password lama pendek (< 8) tetap diterima sekali, tetapi wajib diganti.
                'wajib_ganti_password' => mb_strlen($plain) < self::PANJANG_MINIMAL]);

            return true;
        }

        return false;
    }

    public function nonaktifkan(User $target, User $petugas): void
    {
        $izin = $target->hasRole('wali_santri') ? Izin::AkunWaliReset : Izin::PenggunaKelola;
        Otorisasi::pastikan($petugas, $izin);
        $target->update(['aktif' => false]);
    }

    public static function acak(): string
    {
        return Str::password(10, symbols: false);
    }

    /**
     * Hash dengan pengaturan Laravel (config/hashing.php, BCRYPT_ROUNDS), agar cast `hashed` di model User
     * menerimanya. Di harness lab (tanpa Hash facade) memakai bcrypt biasa.
     */
    public static function hash(string $plain): string
    {
        return function_exists('app') && app()->bound('hash')
            ? \Illuminate\Support\Facades\Hash::make($plain)
            : password_hash($plain, PASSWORD_BCRYPT);
    }
}
