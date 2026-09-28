<?php

namespace App\Services;

use App\Enums\Izin;
use App\Enums\StatusPendaftaran;
use App\Enums\StatusSantri;
use App\Exceptions\AturanDilanggar;
use App\Models\Kelas;
use App\Models\Pendaftaran;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Support\Otorisasi;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Santri baru: formulir diisi calon wali (online) atau Admin Office -> formulir lunas -> diterima.
 * Saat diterima, sistem membuat santri berstatus calon, akun wali (atau menautkan ke akun wali yang
 * sudah ada bila nomor WhatsApp sama, mis. kakak-adik), dan tagihan DSB. Tidak ada pengetikan ulang.
 */
class PendaftaranService
{
    public function __construct(private DataSantriService $dataSantri, private TagihanGenerator $generator, private DataWaliService $dataWali = new DataWaliService()) {}

    /** Formulir publik; tidak perlu login. */
    public function daftar(array $data, TahunAjaran $tujuan): Pendaftaran
    {
        foreach (['nama_calon', 'jenis_kelamin', 'nama_wali', 'telepon_wali', 'alamat'] as $wajib) {
            if (blank($data[$wajib] ?? null)) {
                throw new AturanDilanggar("Kolom {$wajib} wajib diisi.");
            }
        }
        $urut = Pendaftaran::where('tahun_ajaran_id', $tujuan->id)->count() + 1;

        // Nilai sistem menimpa isian formulir (nomor, status, telepon yang dinormalkan).
        return Pendaftaran::create(array_merge($data, [
            'nomor' => sprintf('PSB-%d-%03d', $tujuan->tahun_mulai, $urut),
            'tahun_ajaran_id' => $tujuan->id,
            'telepon_wali' => self::normalTelepon($data['telepon_wali']),
            'status' => StatusPendaftaran::Baru,
        ]));
    }

    public function formulirLunas(Pendaftaran $p, int $biaya, User $petugas, ?string $bukti = null): Pendaftaran
    {
        Otorisasi::pastikan($petugas, Izin::PendaftaranProses);
        $this->pastikanStatus($p, StatusPendaftaran::Baru);
        $p->update([
            'status' => StatusPendaftaran::FormulirLunas, 'biaya_formulir' => $biaya,
            'formulir_lunas_pada' => CarbonImmutable::now(), 'bukti_formulir_path' => $bukti, 'diproses_oleh' => $petugas->id,
        ]);

        return $p;
    }

    /**
     * @return array{santri: Santri, wali: User, akun_baru: bool, password_awal: ?string, dsb: ?\App\Models\Tagihan}
     */
    public function terima(Pendaftaran $p, Kelas $kelas, string $nis, CarbonInterface $jatuhTempoDsb, User $petugas): array
    {
        Otorisasi::pastikan($petugas, Izin::PendaftaranProses);
        $this->pastikanStatus($p, StatusPendaftaran::FormulirLunas);

        return DB::transaction(function () use ($p, $kelas, $nis, $jatuhTempoDsb, $petugas) {
            $ta = $p->tahunAjaran;
            $santri = Santri::create([
                'nis' => $nis, 'nik' => $p->nik_calon, 'nama' => $p->nama_calon, 'jenis_kelamin' => $p->jenis_kelamin,
                'tempat_lahir' => $p->tempat_lahir, 'tanggal_lahir' => $p->tanggal_lahir, 'alamat' => $p->alamat,
                'asal_sekolah' => $p->asal_sekolah, 'status' => StatusSantri::Calon,
                'kode_unik' => $this->dataSantri->kodeUnikBaru(),
            ]);
            $santri->riwayatKelas()->create(['kelas_id' => $kelas->id, 'tahun_ajaran_id' => $ta->id]);

            // Wali yang sudah punya akun (kakak/adik sudah mondok) ditautkan, bukan dibuatkan akun kedua.
            $akun = $this->dataWali->tambahAtauTautkan([
                'name' => $p->nama_wali, 'telepon' => $p->telepon_wali, 'email' => $p->email_wali,
                'nik' => $p->nik_wali, 'alamat' => $p->alamat, 'pekerjaan' => $p->pekerjaan_wali,
            ], [$santri], $p->hubungan_wali, $petugas);
            $wali = $akun['wali'];
            $password = $akun['password_awal'];

            // null bila tarif DSB tahun ajaran tujuan belum diatur; pemanggil wajib memberi tahu petugas.
            $dsb = $this->generator->dsb($santri, $ta, $jatuhTempoDsb);
            $p->update(['status' => StatusPendaftaran::Diterima, 'santri_id' => $santri->id, 'diproses_oleh' => $petugas->id]);

            // Password awal dikirim ke WhatsApp wali oleh pemanggil (jangan disimpan di log).
            return ['santri' => $santri, 'wali' => $wali, 'akun_baru' => $password !== null, 'password_awal' => $password, 'dsb' => $dsb];
        });
    }

    public function tolak(Pendaftaran $p, string $alasan, User $petugas): Pendaftaran
    {
        Otorisasi::pastikan($petugas, Izin::PendaftaranProses);
        if (in_array($p->status, [StatusPendaftaran::Diterima, StatusPendaftaran::Ditolak], true)) {
            throw new AturanDilanggar('Pendaftaran ini sudah diputuskan.');
        }
        $p->update(['status' => StatusPendaftaran::Ditolak, 'catatan' => $alasan, 'diproses_oleh' => $petugas->id]);

        return $p;
    }

    public static function normalTelepon(string $no): string
    {
        $d = preg_replace('/\D/', '', $no);

        return str_starts_with($d, '62') ? '0'.substr($d, 2) : $d;
    }

    private function pastikanStatus(Pendaftaran $p, StatusPendaftaran $harus): void
    {
        if ($p->status !== $harus) {
            throw new AturanDilanggar("Pendaftaran harus berstatus {$harus->value}.");
        }
    }
}
