<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\KalenderAkademik;
use App\Models\Santri;
use App\Models\Tagihan;
use App\Models\TabunganMutasi;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Bentuk JSON API wali. Nama kolom stabil: aplikasi Android bergantung padanya (lihat docs/API-WALI.md). */
final class Format
{
    public const JENIS_MUTASI = [
        'setoran_transfer' => 'Setoran transfer', 'setoran_tunai' => 'Setoran tunai', 'penarikan_tunai' => 'Uang saku',
        'pembayaran_tagihan' => 'Pembayaran', 'koreksi' => 'Koreksi', 'transfer_dana' => 'Pindahan dana',
        'pengembalian' => 'Pengembalian saldo', 'saldo_awal' => 'Saldo awal',
    ];

    public const JENIS_RAPORT = ['pts' => 'Penilaian Tengah Semester', 'pas' => 'Penilaian Akhir Semester', 'pat' => 'Penilaian Akhir Tahun'];

    public static function wali(User $u): array
    {
        return [
            'id' => $u->id, 'nama' => $u->name, 'username' => $u->username,
            'telepon' => $u->telepon, 'telepon_menunggu_verifikasi' => $u->telepon_menunggu,
            'email' => str_ends_with((string) $u->email, '@wali.khandaq') ? null : $u->email,
            'pekerjaan' => $u->pekerjaan, 'alamat' => $u->alamat,
            'wajib_ganti_password' => (bool) $u->wajib_ganti_password,
            'google' => $u->firebase_uid ? ['terhubung' => true, 'email' => $u->email_google] : ['terhubung' => false, 'email' => null],
        ];
    }

    public static function santriRingkas(Santri $s, ?TahunAjaran $ta = null): array
    {
        $ta ??= TahunAjaran::aktif() ?? TahunAjaran::untukTanggal(CarbonImmutable::now());

        return [
            'id' => $s->id, 'nama' => $s->nama, 'nis' => $s->nis, 'kode_transfer' => $s->kode_unik,
            'kelas' => $s->kelasPada($ta)?->nama, 'status' => $s->status->value,
            'hubungan' => $s->pivot?->hubungan,
            'foto_url' => $s->foto ? route('api.v1.anak.foto', ['santri' => $s, 'v' => $s->updated_at?->timestamp]) : null,
        ];
    }

    public static function santriLengkap(Santri $s): array
    {
        return self::santriRingkas($s) + [
            'nisn' => $s->nisn, 'jenis_kelamin' => $s->jenis_kelamin, 'tempat_lahir' => $s->tempat_lahir,
            'tanggal_lahir' => $s->tanggal_lahir?->toDateString(), 'tanggal_masuk' => $s->tanggal_masuk?->toDateString(),
            'alamat' => $s->alamat,
        ];
    }

    public static function mutasi(TabunganMutasi $m): array
    {
        return [
            'id' => $m->id, 'tanggal' => $m->tanggal->toDateString(), 'arah' => $m->arah->value,
            'jenis' => $m->jenis->value, 'jenis_label' => self::JENIS_MUTASI[$m->jenis->value] ?? $m->jenis->value,
            'keterangan' => $m->keterangan, 'nominal' => (int) $m->nominal, 'status' => $m->status->value,
        ];
    }

    public static function tagihan(Tagihan $t, CarbonImmutable $hariIni): array
    {
        return [
            'id' => $t->id, 'keterangan' => $t->keterangan, 'jatuh_tempo' => $t->jatuh_tempo->toDateString(),
            'nominal' => $t->netto(), 'sisa' => (int) $t->sisa(), 'lewat_jatuh_tempo' => $t->jatuh_tempo->lt($hariIni),
        ];
    }

    public static function kegiatan(KalenderAkademik $k): array
    {
        return [
            'id' => $k->id, 'tanggal_mulai' => $k->tanggal_mulai->toDateString(),
            'tanggal_selesai' => $k->tanggal_selesai?->toDateString(), 'kegiatan' => $k->kegiatan,
            'keterangan' => $k->keterangan, 'rentang' => $k->rentang(),
        ];
    }
}
