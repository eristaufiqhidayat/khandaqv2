<?php

namespace App\Console\Commands;

use App\Enums\StatusSantri;
use App\Models\Santri;
use App\Models\User;
use App\Services\AkunService;
use Illuminate\Console\Command;

/**
 * Akun wali uji untuk mencoba portal wali. Ditautkan ke santri yang SUDAH ADA (data asli), jadi hanya untuk
 * masa uji dan harus dihapus sebelum aplikasi dipakai wali sungguhan. Tanpa nomor WhatsApp: tidak ada pesan terkirim.
 * Sinkronisasi data lama melepas tautannya (santri dibuat ulang), jalankan perintah ini lagi setelah sinkron.
 */
class WaliContoh extends Command
{
    protected $signature = 'khandaq:wali-contoh
        {--santri=* : NIS atau kode transfer santri yang ditautkan (boleh lebih dari satu). Kosong = dipilih otomatis}
        {--hapus : Hapus akun wali uji}';

    protected $description = 'Buat/perbarui akun wali uji (username: walicontoh) untuk mencoba portal wali.';

    public const USERNAME = 'walicontoh';

    public function handle(): int
    {
        $u = User::where('username', self::USERNAME)->first();
        if ($this->option('hapus')) {
            if ($u) {
                $u->anak()->detach();
                $u->roles()->detach();
                $u->delete();
                $this->info('Akun wali uji dihapus.');
            } else {
                $this->line('Akun wali uji tidak ada.');
            }

            return self::SUCCESS;
        }

        $anak = $this->pilihSantri();
        if ($anak->isEmpty()) {
            $this->error('Santri tidak ditemukan. Pakai --santri=<NIS atau kode transfer> santri yang ada.');

            return self::FAILURE;
        }

        $pw = AkunService::acak();
        $u ??= new User(['username' => self::USERNAME]);
        $u->fill([
            'name' => 'Wali Contoh (uji)', 'email' => self::USERNAME.'@contoh.test', 'telepon' => null, 'telepon_menunggu' => null,
            'alamat' => 'Alamat contoh', 'pekerjaan' => 'Wiraswasta', 'aktif' => true,
            'password' => AkunService::hash($pw), 'password_lama' => null, 'wajib_ganti_password' => false,
        ])->save();
        $u->syncRoles(['wali_santri']);
        $u->anak()->sync($anak->mapWithKeys(fn (Santri $s) => [$s->id => ['hubungan' => 'wali']])->all());

        $this->info('Akun wali uji siap.');
        $this->table(['Masuk dengan', 'Isi'], [['Username', self::USERNAME], ['Password', $pw]]);
        $this->line('Anak: '.$anak->map(fn ($s) => "{$s->nama} (NIS {$s->nis})")->join(', '));
        $this->warn('Akun ini melihat data santri asli. Hapus setelah selesai uji: php artisan khandaq:wali-contoh --hapus');

        return self::SUCCESS;
    }

    private function pilihSantri()
    {
        $kunci = array_filter((array) $this->option('santri'));
        if ($kunci) {
            return Santri::whereIn('nis', $kunci)->orWhereIn('kode_unik', $kunci)->orderBy('nama')->get();
        }

        // Otomatis: satu santri aktif yang punya raport dan tagihan (paling lengkap untuk dicoba), atau santri aktif pertama.
        return Santri::where('status', StatusSantri::Aktif->value)
            ->withCount(['raport', 'tagihan'])->orderByDesc('raport_count')->orderByDesc('tagihan_count')->orderBy('nama')
            ->limit(1)->get();
    }
}
