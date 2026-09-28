<?php

namespace App\Services\Whatsapp;

use App\Models\User;
use App\Models\WaPesan;
use Carbon\CarbonImmutable;

/**
 * Mengirim username & password sementara ke WhatsApp pemilik akun (akun wali baru, reset password).
 * Password tidak pernah ditampilkan ke petugas dan tidak disimpan: log wa_pesan hanya berisi versi tersamar.
 */
class KirimAksesWali
{
    public function __construct(private PengirimWa $pengirim) {}

    public function aktif(): bool
    {
        return $this->pengirim->gateway()->nama() !== 'log';
    }

    /** @return array{terkirim: bool, pesan: string} pesan untuk ditampilkan ke petugas (tanpa password) */
    public function kirim(User $user, string $password, string $alasan = 'Akun portal wali santri Khandaq'): array
    {
        if (! $user->telepon) {
            return ['terkirim' => false, 'pesan' => 'Akun belum punya nomor WhatsApp; password tidak terkirim. Isi nomornya lalu reset password.'];
        }
        if (! $this->aktif()) {
            return ['terkirim' => false, 'pesan' => 'WhatsApp belum diatur (WA_VENDOR=log), jadi password tidak terkirim. Setelah WhatsApp aktif, gunakan Reset password.'];
        }
        $templat = "Assalamu'alaikum {nama},\n{alasan}.\nUsername: {username}\nPassword sementara: {password}\nMasuk di {url} lalu ganti password Anda.";
        $isi = fn (string $pw) => strtr($templat, ['{nama}' => $user->name, '{alasan}' => $alasan,
            '{username}' => $user->username, '{password}' => $pw, '{url}' => url('/masuk')]);

        $hasil = $this->pengirim->gateway()->kirim($user->telepon, $isi($password), $this->pengirim->butuhTemplate() ? config('khandaq.whatsapp.template_akses', 'akses_akun') : null);
        WaPesan::create([
            'user_id' => $user->id, 'telepon' => $user->telepon, 'isi' => $isi('••••••'),
            'status' => $hasil->ok ? 'terkirim' : 'gagal', 'vendor' => $this->pengirim->gateway()->nama(),
            'id_vendor' => $hasil->idVendor, 'galat' => $hasil->galat,
            'dikirim_pada' => CarbonImmutable::now(), 'status_pada' => CarbonImmutable::now(),
        ]);

        return $hasil->ok
            ? ['terkirim' => true, 'pesan' => 'Username dan password sementara dikirim ke WhatsApp '.$user->telepon.'.']
            : ['terkirim' => false, 'pesan' => 'Pengiriman WhatsApp ke '.$user->telepon.' gagal ('.($hasil->galat ?? 'tanpa keterangan').'). Coba Reset password lagi nanti.'];
    }
}
