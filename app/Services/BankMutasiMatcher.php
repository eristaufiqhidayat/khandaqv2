<?php

namespace App\Services;

use App\Enums\ArahMutasi;
use App\Enums\Izin;
use App\Enums\JenisMutasi;
use App\Enums\StatusBankMutasi;
use App\Enums\StatusMutasi;
use App\Exceptions\AturanDilanggar;
use App\Models\BankImpor;
use App\Models\BankMutasi;
use App\Models\Rekening;
use App\Models\Santri;
use App\Models\TabunganMutasi;
use App\Models\User;
use App\Support\Otorisasi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Impor mutasi rekening BSI dan pencocokan otomatis dengan setoran transfer yang dilaporkan.
 *
 * Kriteria cocok: nominal sama persis, tanggal +-N hari, dan santri teridentifikasi dari
 * kode unik (3 digit terakhir nominal, atau "KHQ 123" di berita transfer).
 * Yang tidak cocok masuk antrian "ditinjau" untuk Admin Office.
 */
class BankMutasiMatcher
{
    public function __construct(private TabunganService $tabungan, private int $toleransiHari = 2) {}

    /**
     * Impor CSV. $kolom memetakan nama kolom baku -> judul kolom di file BSI.
     * Format file dari BSI Net/CMS perlu dicek; sesuaikan pemetaan tanpa mengubah kode.
     */
    public function imporCsv(Rekening $rekening, string $path, User $pengimpor, array $kolom = [], string $formatTanggal = 'd/m/Y H:i'): BankImpor
    {
        Otorisasi::pastikan($pengimpor, Izin::BankImpor);
        $kolom += ['tanggal' => 'tanggal', 'no_referensi' => 'no_referensi', 'deskripsi' => 'deskripsi', 'debet' => 'debet', 'kredit' => 'kredit', 'saldo' => 'saldo'];

        $fh = fopen($path, 'r');
        $judul = array_map(fn ($h) => strtolower(trim($h)), fgetcsv($fh, escape: '\\'));
        $idx = [];
        foreach ($kolom as $baku => $nama) {
            $pos = array_search(strtolower($nama), $judul, true);
            if ($pos === false && in_array($baku, ['tanggal', 'no_referensi', 'kredit'], true)) {
                throw new AturanDilanggar("Kolom '{$nama}' tidak ditemukan di file.");
            }
            $idx[$baku] = $pos === false ? null : $pos;
        }

        return DB::transaction(function () use ($fh, $idx, $rekening, $path, $pengimpor, $formatTanggal) {
            $impor = BankImpor::create(['rekening_id' => $rekening->id, 'nama_file' => basename($path), 'diimpor_oleh' => $pengimpor->id]);
            $angka = fn ($v) => (int) round((float) str_replace([',', ' '], ['', ''], (string) $v));
            $tanggalList = [];
            while (($row = fgetcsv($fh, escape: '\\')) !== false) {
                if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $ref = trim($row[$idx['no_referensi']]);
                if (BankMutasi::where('rekening_id', $rekening->id)->where('no_referensi', $ref)->exists()) {
                    continue; // sudah pernah diimpor
                }
                $tgl = CarbonImmutable::createFromFormat($formatTanggal, trim($row[$idx['tanggal']]));
                $tanggalList[] = $tgl;
                BankMutasi::create([
                    'bank_impor_id' => $impor->id, 'rekening_id' => $rekening->id, 'tanggal' => $tgl, 'no_referensi' => $ref,
                    'deskripsi' => $idx['deskripsi'] !== null ? trim($row[$idx['deskripsi']]) : null,
                    'debet' => $idx['debet'] !== null ? $angka($row[$idx['debet']]) : 0,
                    'kredit' => $angka($row[$idx['kredit']]),
                    'saldo' => $idx['saldo'] !== null ? $angka($row[$idx['saldo']]) : null,
                    'status' => StatusBankMutasi::Baru,
                ]);
            }
            fclose($fh);

            $cocok = 0;
            foreach ($impor->baris()->get() as $baris) {
                $cocok += (int) ($this->cocokkan($baris) === StatusBankMutasi::Cocok);
            }
            $impor->update([
                'jumlah_baris' => $impor->baris()->count(), 'jumlah_cocok' => $cocok,
                'periode_awal' => $tanggalList ? min($tanggalList)->toDateString() : null,
                'periode_akhir' => $tanggalList ? max($tanggalList)->toDateString() : null,
            ]);

            return $impor;
        });
    }

    public function cocokkan(BankMutasi $baris): StatusBankMutasi
    {
        if ($baris->kredit <= 0) {
            $baris->update(['status' => StatusBankMutasi::Diabaikan, 'catatan' => 'Debet / bukan setoran']);

            return StatusBankMutasi::Diabaikan;
        }
        $santri = $this->deteksiSantri($baris);
        $kandidat = TabunganMutasi::query()
            ->where('status', StatusMutasi::Pending->value)
            ->where('jenis', JenisMutasi::SetoranTransfer->value)
            ->whereNull('bank_mutasi_id')
            ->where('nominal', $baris->kredit)
            ->whereBetween('tanggal', [$baris->tanggal->subDays($this->toleransiHari)->startOfDay(), $baris->tanggal->addDays($this->toleransiHari)->endOfDay()])
            ->when($santri, fn ($q) => $q->where('santri_id', $santri->id))
            ->get();

        if ($kandidat->count() === 1) {
            $this->tabungan->verifikasi($kandidat->first(), null, $baris);
            $baris->update(['status' => StatusBankMutasi::Cocok, 'santri_id_terdeteksi' => $kandidat->first()->santri_id]);

            return StatusBankMutasi::Cocok;
        }

        $baris->update([
            'status' => StatusBankMutasi::Ditinjau,
            'santri_id_terdeteksi' => $santri?->id,
            'catatan' => match (true) {
                $kandidat->count() > 1 => 'Lebih dari satu setoran cocok',
                $santri !== null => 'Kode unik dikenali, tetapi wali belum melaporkan setoran',
                default => 'Santri tidak teridentifikasi',
            },
        ]);

        return StatusBankMutasi::Ditinjau;
    }

    /** Admin Office menerima baris "ditinjau" sebagai setoran santri tertentu (dicatat & diverifikasi sekaligus oleh bank). */
    public function terimaSebagaiSetoran(BankMutasi $baris, Santri $santri, User $petugas): TabunganMutasi
    {
        Otorisasi::pastikan($petugas, Izin::SetoranVerifikasi);
        if ($baris->status !== StatusBankMutasi::Ditinjau || $baris->kredit <= 0) {
            throw new AturanDilanggar('Hanya baris kredit berstatus ditinjau yang bisa diterima.');
        }

        return DB::transaction(function () use ($baris, $santri, $petugas) {
            $mutasi = TabunganMutasi::create([
                'santri_id' => $santri->id, 'tanggal' => $baris->tanggal, 'arah' => ArahMutasi::Kredit,
                'jenis' => JenisMutasi::SetoranTransfer, 'nominal' => $baris->kredit,
                'keterangan' => 'Transfer '.$baris->no_referensi, 'status' => StatusMutasi::Pending,
                'rekening_id' => $baris->rekening_id, 'dicatat_oleh' => $petugas->id,
            ]);
            $this->tabungan->verifikasi($mutasi, null, $baris);
            $baris->update(['status' => StatusBankMutasi::Cocok, 'santri_id_terdeteksi' => $santri->id]);

            return $mutasi->refresh();
        });
    }

    public function deteksiSantri(BankMutasi $baris): ?Santri
    {
        if (preg_match('/\bKHQ[\s\-:]*(\d{3})\b/i', (string) $baris->deskripsi, $m)) {
            $s = Santri::where('kode_unik', $m[1])->first();
            if ($s) {
                return $s;
            }
        }
        $akhiran = str_pad((string) ($baris->kredit % 1000), 3, '0', STR_PAD_LEFT);
        if ($akhiran !== '000') {
            return Santri::where('kode_unik', $akhiran)->first();
        }

        return null;
    }
}
