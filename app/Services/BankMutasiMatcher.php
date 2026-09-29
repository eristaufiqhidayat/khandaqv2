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
        $judul = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($fh, escape: '') ?: []);
        // Rekening koran BSI (BSINet/CMS): Date, FT Number, Description, Currency, Amount, DB, CR, Balance.
        // Nominal di satu kolom, arah ditandai "DB"/"CR". Dikenali otomatis, pemetaan kolom diabaikan.
        $formatBsi = ! array_diff(['date', 'ft number', 'amount', 'db', 'cr'], $judul);
        if ($formatBsi) {
            $kolom = ['tanggal' => 'date', 'no_referensi' => 'ft number', 'deskripsi' => 'description', 'nominal' => 'amount',
                'tanda_db' => 'db', 'tanda_cr' => 'cr', 'saldo' => 'balance', 'debet' => '-', 'kredit' => '-'];
        }
        $idx = [];
        foreach ($kolom as $baku => $nama) {
            $pos = array_search(strtolower($nama), $judul, true);
            if ($pos === false && in_array($baku, $formatBsi ? ['tanggal', 'no_referensi', 'nominal'] : ['tanggal', 'no_referensi', 'kredit'], true)) {
                throw new AturanDilanggar("Kolom '{$nama}' tidak ditemukan di file.");
            }
            $idx[$baku] = $pos === false ? null : $pos;
        }

        return DB::transaction(function () use ($fh, $idx, $rekening, $path, $pengimpor, $formatTanggal, $formatBsi) {
            $impor = BankImpor::create(['rekening_id' => $rekening->id, 'nama_file' => basename($path), 'diimpor_oleh' => $pengimpor->id]);
            $angka = fn ($v) => (int) round((float) str_replace([',', ' '], ['', ''], (string) $v));
            $tanggalList = [];
            while (($row = fgetcsv($fh, escape: '')) !== false) {
                if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $ref = self::referensi($row[$idx['no_referensi']] ?? '');
                if ($ref === '') {
                    continue;
                }
                if ($formatBsi) {
                    $n = $angka($row[$idx['nominal']] ?? 0);
                    $debet = strtoupper(trim($row[$idx['tanda_db']] ?? '')) === 'DB' ? $n : 0;
                    $kredit = strtoupper(trim($row[$idx['tanda_cr']] ?? '')) === 'CR' ? $n : 0;
                } else {
                    $debet = $idx['debet'] !== null ? $angka($row[$idx['debet']] ?? 0) : 0;
                    $kredit = $angka($row[$idx['kredit']] ?? 0);
                }
                // Satu FT bisa dipakai transfer + biaya adminnya; yang sama persis (FT & nominal) sudah pernah masuk.
                if (BankMutasi::where('rekening_id', $rekening->id)->where('no_referensi', $ref)->where('debet', $debet)->where('kredit', $kredit)->exists()) {
                    continue;
                }
                $tgl = self::tanggal(trim($row[$idx['tanggal']]), $formatTanggal);
                $tanggalList[] = $tgl;
                BankMutasi::create([
                    'bank_impor_id' => $impor->id, 'rekening_id' => $rekening->id, 'tanggal' => $tgl, 'no_referensi' => $ref,
                    'deskripsi' => $idx['deskripsi'] !== null ? mb_substr(trim($row[$idx['deskripsi']] ?? ''), 0, 255) : null,
                    'debet' => $debet, 'kredit' => $kredit,
                    'saldo' => $idx['saldo'] !== null && isset($row[$idx['saldo']]) ? $angka($row[$idx['saldo']]) : null,
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
        $anakPengirim = $santri ? null : $this->anakPengirim($baris);
        $kandidat = TabunganMutasi::query()
            ->where('status', StatusMutasi::Pending->value)
            ->where('jenis', JenisMutasi::SetoranTransfer->value)
            ->whereNull('bank_mutasi_id')
            ->where('nominal', $baris->kredit)
            ->whereBetween('tanggal', [$baris->tanggal->subDays($this->toleransiHari)->startOfDay(), $baris->tanggal->addDays($this->toleransiHari)->endOfDay()])
            ->when($santri, fn ($q) => $q->where('santri_id', $santri->id))
            ->get();
        // Beberapa laporan bernominal sama: pakai nama pengirim di berita transfer untuk memilih.
        if ($kandidat->count() > 1 && $anakPengirim) {
            $milikPengirim = $kandidat->whereIn('santri_id', $anakPengirim['santri']);
            if ($milikPengirim->isNotEmpty()) {
                $kandidat = $milikPengirim;
            }
        }

        if ($kandidat->count() === 1) {
            $this->tabungan->verifikasi($kandidat->first(), null, $baris);
            $baris->update(['status' => StatusBankMutasi::Cocok, 'santri_id_terdeteksi' => $kandidat->first()->santri_id]);

            return StatusBankMutasi::Cocok;
        }

        $baris->update([
            'status' => StatusBankMutasi::Ditinjau,
            'santri_id_terdeteksi' => $santri?->id ?? (count($anakPengirim['santri'] ?? []) === 1 ? $anakPengirim['santri'][0] : null),
            'catatan' => match (true) {
                $kandidat->count() > 1 => 'Lebih dari satu setoran cocok',
                $santri !== null => 'Kode unik dikenali, tetapi wali belum melaporkan setoran',
                $anakPengirim !== null => 'Pengirim dikenali sebagai wali '.$anakPengirim['nama'].', tetapi belum ada laporan setoran',
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

    /** Baris "ditinjau" yang ternyata bukan setoran santri (biaya bank, dana masuk lain). */
    public function abaikan(BankMutasi $baris, User $petugas, string $catatan): void
    {
        Otorisasi::pastikan($petugas, Izin::SetoranVerifikasi);
        if ($baris->status !== StatusBankMutasi::Ditinjau) {
            throw new AturanDilanggar('Hanya baris berstatus ditinjau yang bisa diabaikan.');
        }
        $baris->update(['status' => StatusBankMutasi::Diabaikan, 'catatan' => $catatan]);
    }

    /** @var array<string, array{nama: string, santri: list<int>}>|null  nama wali ternormalisasi -> anak aktif */
    private ?array $daftarWali = null;

    /**
     * Wali pengirim dari berita transfer ("BIFAST - TRF Dari - Bank BCA AMIN RAHMAT PERMANA",
     * "Trf Dari - 014 - MUHAMAD SOLEH H"). Hanya bila tepat satu wali cocok; nama pendek (< 5 huruf) diabaikan.
     *
     * @return array{nama: string, santri: list<int>}|null
     */
    public function anakPengirim(BankMutasi $baris): ?array
    {
        $teks = self::normalNama((string) $baris->deskripsi);
        $pos = strrpos($teks, ' DARI ');
        if ($pos === false) {
            return null;
        }
        $pengirim = ' '.substr($teks, $pos + 6).' ';
        $this->daftarWali ??= User::where('aktif', true)->whereHas('anak')
            ->with(['anak' => fn ($q) => $q->where('status', \App\Enums\StatusSantri::Aktif->value)])->get()
            ->filter(fn (User $u) => $u->anak->isNotEmpty() && strlen(str_replace(' ', '', self::normalNama($u->name))) >= 5)
            ->groupBy(fn (User $u) => self::normalNama($u->name))
            ->map(fn ($g) => ['nama' => $g->first()->name, 'santri' => $g->flatMap(fn (User $u) => $u->anak->pluck('id'))->unique()->values()->all()])
            ->all();
        $cocok = array_filter($this->daftarWali, fn ($w, $nama) => str_contains($pengirim, " {$nama} "), ARRAY_FILTER_USE_BOTH);

        return count($cocok) === 1 ? reset($cocok) : null;
    }

    private static function normalNama(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 ]/', ' ', strtoupper($s))));
    }

    /** Nomor FT tanpa akhiran cabang/kanal ("FT26244K6PGN\BNK" -> "FT26244K6PGN"), agar sama dengan notifikasi email. */
    public static function referensi(string $ref): string
    {
        return strtoupper(trim((string) preg_replace('/\\\\.*$/', '', trim($ref))));
    }

    private static function tanggal(string $s, string $format): CarbonImmutable
    {
        foreach ([$format, 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'] as $f) {
            try {
                $t = CarbonImmutable::createFromFormat($f, $s);
                if ($t !== false && $t->format($f) === $s) {
                    return $t;
                }
            } catch (\Throwable) {
            }
        }

        return CarbonImmutable::parse($s);
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
