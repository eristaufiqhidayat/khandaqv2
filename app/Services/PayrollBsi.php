<?php

namespace App\Services;

use App\Exceptions\AturanDilanggar;
use App\Models\Pegawai;
use App\Models\Penggajian;
use App\Models\PenggajianRincian;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Berkas payroll BSI (unggah di BSINet > Payroll). Teks berpemisah "|", setiap baris diakhiri "|":
 *
 *   0||2026-08-31|19|31275000|                                   <- tanggal transfer | jumlah baris | total
 *   0|BENEFICIARY ACCT (35)|BENEFICIARY ACCT NAME |...|SMS NOTIF (100)|   <- judul kolom (tetap)
 *   1|8092949010|Murtiningsih|IDR|4510000|123|Gaji Agustus 2026|2|email@contoh.id|08128815569|
 */
class PayrollBsi
{
    public const JUDUL_KOLOM = '0|BENEFICIARY ACCT (35)|BENEFICIARY ACCT NAME |CREDIT AMOUNT CCY|AMOUNT|CUST REF NO|MESSAGE (65)|EXTENDED PAYMENT DETAIL|BENEFICIARY NOTIF EMAIL(100)|SMS NOTIF (100)|';

    /** Nilai tetap di berkas contoh dari BSI. */
    public const CUST_REF = '123';
    public const EXTENDED_DETAIL = '2';

    public static function pesanBawaan(CarbonImmutable $periode): string
    {
        return 'Gaji '.$periode->translatedFormat('F Y');
    }

    /** Buat penggajian baru berisi semua pegawai aktif. Nominal: dari penggajian sebelumnya, atau nominal tetap. */
    public function buat(CarbonImmutable $periode, CarbonImmutable $tanggalTransfer, string $pesan, User $pembuat, bool $dariSebelumnya = true): Penggajian
    {
        $periode = $periode->startOfMonth();

        return DB::transaction(function () use ($periode, $tanggalTransfer, $pesan, $pembuat, $dariSebelumnya) {
            $sebelumnya = $dariSebelumnya ? Penggajian::where('periode', '<=', $periode->toDateString())->latest('periode')->latest('id')->first() : null;
            $nominalLalu = $sebelumnya ? $sebelumnya->rincian()->pluck('nominal', 'pegawai_id') : collect();

            $p = Penggajian::create([
                'judul' => self::pesanBawaan($periode), 'periode' => $periode, 'tanggal_transfer' => $tanggalTransfer,
                'pesan' => self::bersih($pesan, 65) ?: self::pesanBawaan($periode), 'status' => Penggajian::DRAF, 'dibuat_oleh' => $pembuat->id,
            ]);
            foreach (Pegawai::aktif()->urut()->get() as $i => $pg) {
                $p->rincian()->create(['pegawai_id' => $pg->id, 'nominal' => (int) ($nominalLalu[$pg->id] ?? $pg->nominal_tetap), 'urutan' => $i + 1]);
            }

            return $p;
        });
    }

    /**
     * Baris efektif untuk berkas: data rekening dari salinan (bila final) atau data pegawai saat ini (draf).
     *
     * @return Collection<int, array{id: int, no_rekening: string, nama_rekening: string, nominal: int, pesan: string, email: string, telepon: string}>
     */
    public function baris(Penggajian $p): Collection
    {
        return $p->rincian()->with('pegawai')->get()->map(function (PenggajianRincian $r) use ($p) {
            $pakaiSalinan = $p->final() && $r->no_rekening !== null;

            return [
                'id' => $r->id,
                'no_rekening' => preg_replace('/\D/', '', (string) ($pakaiSalinan ? $r->no_rekening : $r->pegawai->no_rekening)),
                'nama_rekening' => self::bersih($pakaiSalinan ? $r->nama_rekening : $r->pegawai->nama_rekening, 100),
                'nominal' => (int) $r->nominal,
                'pesan' => self::bersih($r->pesan ?: $p->pesan, 65),
                'email' => self::bersih($pakaiSalinan ? $r->email : $r->pegawai->email, 100),
                'telepon' => preg_replace('/\D/', '', (string) ($pakaiSalinan ? $r->telepon : $r->pegawai->telepon)),
            ];
        });
    }

    /** @return array<int, list<string>> kesalahan per id rincian (berkas tidak bisa dibuat selama ada) */
    public function periksa(Penggajian $p): array
    {
        $galat = [];
        foreach ($this->baris($p) as $b) {
            $e = [];
            if ($b['no_rekening'] === '' || strlen($b['no_rekening']) < 10 || strlen($b['no_rekening']) > 35) {
                $e[] = 'Nomor rekening tidak valid (minimal 10 digit)';
            }
            if ($b['nama_rekening'] === '') {
                $e[] = 'Nama pemilik rekening kosong';
            }
            if ($b['nominal'] <= 0) {
                $e[] = 'Nominal belum diisi';
            }
            if ($b['email'] !== '' && ! filter_var($b['email'], FILTER_VALIDATE_EMAIL)) {
                $e[] = 'Format email salah';
            }
            if ($e) {
                $galat[$b['id']] = $e;
            }
        }

        return $galat;
    }

    /** Isi berkas .txt payroll BSI. */
    public function berkas(Penggajian $p): string
    {
        $baris = $this->baris($p);
        if ($baris->isEmpty()) {
            throw new AturanDilanggar('Belum ada penerima gaji.');
        }
        if ($this->periksa($p)) {
            throw new AturanDilanggar('Masih ada data yang salah. Perbaiki baris bertanda merah sebelum mengunduh berkas.');
        }
        $isi = ['0||'.$p->tanggal_transfer->format('Y-m-d').'|'.$baris->count().'|'.$baris->sum('nominal').'|', self::JUDUL_KOLOM];
        foreach ($baris->values() as $i => $b) {
            $isi[] = implode('|', [$i + 1, $b['no_rekening'], $b['nama_rekening'], 'IDR', $b['nominal'], self::CUST_REF,
                $b['pesan'], self::EXTENDED_DETAIL, $b['email'], $b['telepon']]).'|';
        }

        return implode("\n", $isi);
    }

    /** Kunci penggajian setelah berkas diunggah ke BSI: data rekening disalin, nominal tidak bisa diubah lagi. */
    public function finalkan(Penggajian $p, User $petugas): void
    {
        if ($p->final()) {
            return;
        }
        $this->berkas($p); // memastikan tidak ada kesalahan
        DB::transaction(function () use ($p, $petugas) {
            foreach ($p->rincian()->with('pegawai')->get() as $r) {
                $r->update(['no_rekening' => $r->pegawai->no_rekening, 'nama_rekening' => $r->pegawai->nama_rekening,
                    'email' => $r->pegawai->email, 'telepon' => $r->pegawai->telepon]);
            }
            $p->update(['status' => Penggajian::FINAL, 'difinalkan_oleh' => $petugas->id, 'difinalkan_pada' => now()]);
        });
    }

    /**
     * Uraikan berkas payroll BSI yang pernah dikirim (untuk mengisi data pegawai).
     *
     * @return list<array{no_rekening: string, nama_rekening: string, nominal: int, pesan: string, email: ?string, telepon: ?string}>
     */
    public function urai(string $isi): array
    {
        $hasil = [];
        foreach (preg_split('/\r\n|\r|\n/', $isi) as $baris) {
            $k = explode('|', trim($baris));
            if (count($k) < 10 || $k[0] === '0' || ! ctype_digit(trim($k[0]))) {
                continue; // header, judul kolom, atau baris kosong
            }
            $rek = preg_replace('/\D/', '', $k[1]);
            if ($rek === '') {
                continue;
            }
            $hasil[] = [
                'no_rekening' => $rek, 'nama_rekening' => self::bersih($k[2], 100), 'nominal' => (int) preg_replace('/\D/', '', $k[4]),
                'pesan' => self::bersih($k[6], 65), 'email' => self::bersih($k[8], 100) ?: null, 'telepon' => preg_replace('/\D/', '', $k[9]) ?: null,
            ];
        }

        return $hasil;
    }

    /**
     * Isi/perbarui data pegawai dari berkas payroll lama. Kunci: nomor rekening.
     *
     * @return array{baru: int, diperbarui: int}
     */
    public function imporPegawai(string $isi): array
    {
        $baris = $this->urai($isi);
        if (! $baris) {
            throw new AturanDilanggar('Berkas tidak berisi baris payroll BSI (format: No|Rekening|Nama|IDR|Nominal|...).');
        }
        $jumlah = ['baru' => 0, 'diperbarui' => 0];
        $urutan = (int) Pegawai::max('urutan');
        DB::transaction(function () use ($baris, &$jumlah, &$urutan) {
            foreach ($baris as $b) {
                $pg = Pegawai::where('no_rekening', $b['no_rekening'])->first();
                $data = ['nama_rekening' => $b['nama_rekening'], 'nominal_tetap' => $b['nominal'],
                    'email' => $b['email'], 'telepon' => $b['telepon'], 'aktif' => true];
                if ($pg) {
                    $pg->update($data);
                    $jumlah['diperbarui']++;
                } else {
                    Pegawai::create($data + ['nama' => $b['nama_rekening'], 'no_rekening' => $b['no_rekening'], 'urutan' => ++$urutan]);
                    $jumlah['baru']++;
                }
            }
        });

        return $jumlah;
    }

    /** Buang pemisah "|" dan baris baru (akan merusak berkas), rapikan spasi, potong sesuai batas kolom BSI. */
    public static function bersih(?string $s, int $maks): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', str_replace('|', ' ', (string) $s)));

        return mb_substr($s, 0, $maks);
    }
}
