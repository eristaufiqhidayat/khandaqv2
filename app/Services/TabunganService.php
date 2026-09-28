<?php

namespace App\Services;

use App\Enums\ArahMutasi;
use App\Enums\Frekuensi;
use App\Enums\Izin;
use App\Enums\JenisMutasi;
use App\Enums\StatusMutasi;
use App\Enums\StatusTagihan;
use App\Exceptions\AturanDilanggar;
use App\Models\BankMutasi;
use App\Models\Dana;
use App\Models\JenisTagihan;
use App\Models\Rekening;
use App\Models\Santri;
use App\Models\Tagihan;
use App\Models\TabunganMutasi;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Support\Otorisasi;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Semua perubahan saldo tabungan lewat kelas ini, masing-masing dalam satu transaksi database.
 *
 * Alur setoran:
 *   transfer  -> pending -> (cocok dengan mutasi BSI / diverifikasi admin) -> terverifikasi
 *   tunai     -> langsung terverifikasi
 *   setelah terverifikasi: potong infak per setoran, lalu lunasi tagihan potong-otomatis
 *   (SPP, laundry, kesehatan) yang sudah terbit, urut jatuh tempo.
 */
class TabunganService
{
    /** @param int $batasTarikHarian 0 = tanpa batas */
    public function __construct(private int $batasTarikHarian = 0) {}

    public function catatSetoran(
        Santri $santri,
        int $nominal,
        CarbonInterface $tanggal,
        bool $transfer,
        User $pencatat,
        ?string $buktiPath = null,
        ?string $keterangan = null,
    ): TabunganMutasi {
        $this->pastikanPositif($nominal);
        // Wali boleh melaporkan transfer anaknya sendiri; staf butuh izin.
        $olehWali = $transfer && $pencatat->adalahWaliDari($santri);
        if (! $olehWali) {
            Otorisasi::pastikan($pencatat, Izin::SetoranCatat);
        }

        return DB::transaction(function () use ($santri, $nominal, $tanggal, $transfer, $pencatat, $buktiPath, $keterangan) {
            $mutasi = TabunganMutasi::create([
                'santri_id' => $santri->id,
                'tanggal' => $tanggal,
                'arah' => ArahMutasi::Kredit,
                'jenis' => $transfer ? JenisMutasi::SetoranTransfer : JenisMutasi::SetoranTunai,
                'nominal' => $nominal,
                'keterangan' => $keterangan ?? ($transfer ? 'Setoran transfer' : 'Setoran tunai'),
                'status' => $transfer ? StatusMutasi::Pending : StatusMutasi::Terverifikasi,
                'rekening_id' => Rekening::kode($transfer ? 'BSI' : 'KAS')->id,
                'bukti_path' => $buktiPath,
                'dicatat_oleh' => $pencatat->id,
                'diverifikasi_oleh' => $transfer ? null : $pencatat->id,
                'diverifikasi_pada' => $transfer ? null : CarbonImmutable::now(),
            ]);
            if (! $transfer) {
                $this->prosesSetelahKredit($mutasi);
            }

            return $mutasi;
        });
    }

    /**
     * Verifikasi setoran transfer. $verifikator null = diverifikasi sistem (cocok otomatis dengan mutasi BSI).
     * Verifikasi manual wajib oleh orang lain dari pencatat (pemisahan tugas).
     */
    public function verifikasi(TabunganMutasi $mutasi, ?User $verifikator, ?BankMutasi $bank = null): TabunganMutasi
    {
        if ($mutasi->status !== StatusMutasi::Pending) {
            throw new AturanDilanggar('Hanya setoran berstatus pending yang bisa diverifikasi.');
        }
        if ($verifikator) {
            Otorisasi::pastikan($verifikator, Izin::SetoranVerifikasi);
            if ($verifikator->id === $mutasi->dicatat_oleh) {
                throw new AturanDilanggar('Setoran tidak boleh diverifikasi oleh orang yang mencatatnya.');
            }
        } elseif (! $bank) {
            throw new AturanDilanggar('Verifikasi sistem wajib menyertakan mutasi bank.');
        }

        return DB::transaction(function () use ($mutasi, $verifikator, $bank) {
            $mutasi->update([
                'status' => StatusMutasi::Terverifikasi,
                'diverifikasi_oleh' => $verifikator?->id,
                'diverifikasi_pada' => CarbonImmutable::now(),
                'bank_mutasi_id' => $bank?->id,
            ]);
            $this->prosesSetelahKredit($mutasi);

            return $mutasi;
        });
    }

    public function tolak(TabunganMutasi $mutasi, User $verifikator, string $alasan): void
    {
        Otorisasi::pastikan($verifikator, Izin::SetoranVerifikasi);
        if ($mutasi->status !== StatusMutasi::Pending) {
            throw new AturanDilanggar('Hanya setoran pending yang bisa ditolak.');
        }
        $mutasi->update(['status' => StatusMutasi::Ditolak, 'keterangan' => trim($mutasi->keterangan.' | Ditolak: '.$alasan)]);
    }

    /** Penarikan uang saku tunai. Tidak boleh membuat saldo negatif. */
    public function tarikTunai(Santri $santri, int $nominal, CarbonInterface $tanggal, User $petugas, ?string $keterangan = null): TabunganMutasi
    {
        Otorisasi::pastikan($petugas, Izin::PenarikanCatat);
        $this->pastikanPositif($nominal);

        return DB::transaction(function () use ($santri, $nominal, $tanggal, $petugas, $keterangan) {
            $this->kunciSantri($santri);
            if ($santri->saldo() < $nominal) {
                throw new AturanDilanggar('Saldo tidak cukup untuk penarikan.');
            }
            if ($this->batasTarikHarian > 0) {
                $hariIni = (int) $santri->mutasi()
                    ->where('jenis', JenisMutasi::PenarikanTunai->value)
                    ->whereBetween('tanggal', [CarbonImmutable::parse($tanggal)->startOfDay(), CarbonImmutable::parse($tanggal)->endOfDay()])
                    ->sum('nominal');
                if ($hariIni + $nominal > $this->batasTarikHarian) {
                    throw new AturanDilanggar('Melebihi batas penarikan harian.');
                }
            }

            return TabunganMutasi::create([
                'santri_id' => $santri->id, 'tanggal' => $tanggal,
                'arah' => ArahMutasi::Debit, 'jenis' => JenisMutasi::PenarikanTunai,
                'nominal' => $nominal, 'keterangan' => $keterangan ?? 'Uang saku tunai',
                'status' => StatusMutasi::Terverifikasi, 'rekening_id' => Rekening::kode('KAS')->id,
                'dicatat_oleh' => $petugas->id, 'diverifikasi_oleh' => $petugas->id, 'diverifikasi_pada' => CarbonImmutable::now(),
            ]);
        });
    }

    /** Bayar tagihan dari saldo secara manual (DSB, DU, PTS, PAS, kegiatan), boleh sebagian bila boleh dicicil. */
    public function bayarTagihan(Tagihan $tagihan, int $nominal, CarbonInterface $tanggal, User $petugas): TabunganMutasi
    {
        Otorisasi::pastikan($petugas, Izin::TagihanKelola);
        $this->pastikanPositif($nominal);

        return DB::transaction(function () use ($tagihan, $nominal, $tanggal, $petugas) {
            $santri = $tagihan->santri;
            $this->kunciSantri($santri);
            $tagihan->refresh();
            if (! in_array($tagihan->status->value, StatusTagihan::terbuka(), true)) {
                throw new AturanDilanggar('Tagihan sudah lunas atau dibatalkan.');
            }
            if ($nominal > $tagihan->sisa()) {
                throw new AturanDilanggar('Nominal melebihi sisa tagihan.');
            }
            if (! $tagihan->jenisTagihan->boleh_dicicil && $nominal < $tagihan->sisa()) {
                throw new AturanDilanggar('Tagihan ini tidak boleh dicicil.');
            }
            if ($santri->saldo() < $nominal) {
                throw new AturanDilanggar('Saldo tidak cukup.');
            }

            return $this->debitTagihan($tagihan, $nominal, $tanggal, $petugas);
        });
    }

    /** Dana DSB/DU yang dipindah ke tabungan (dulu TKKOR "SPP Juli dari DSB"), tercatat sebagai transfer antar dana. */
    public function transferDana(Santri $santri, Dana $asal, int $nominal, CarbonInterface $tanggal, string $keterangan, User $petugas): TabunganMutasi
    {
        Otorisasi::pastikan($petugas, Izin::KeringananSetujui);
        $this->pastikanPositif($nominal);

        return DB::transaction(function () use ($santri, $asal, $nominal, $tanggal, $keterangan, $petugas) {
            $m = TabunganMutasi::create([
                'santri_id' => $santri->id, 'tanggal' => $tanggal, 'arah' => ArahMutasi::Kredit,
                'jenis' => JenisMutasi::TransferDana, 'nominal' => $nominal, 'keterangan' => $keterangan,
                'status' => StatusMutasi::Terverifikasi, 'dana_id' => $asal->id,
                'dicatat_oleh' => $petugas->id, 'diverifikasi_oleh' => $petugas->id, 'diverifikasi_pada' => CarbonImmutable::now(),
            ]);
            $this->alokasiOtomatis($santri, $tanggal);

            return $m;
        });
    }

    /** Koreksi saldo (salah input, dll.). Wajib alasan; diperlakukan seperti transaksi biasa oleh tutup buku. */
    public function koreksi(Santri $santri, ArahMutasi $arah, int $nominal, CarbonInterface $tanggal, string $alasan, User $petugas): TabunganMutasi
    {
        Otorisasi::pastikan($petugas, Izin::SetoranVerifikasi);
        $this->pastikanPositif($nominal);

        return DB::transaction(function () use ($santri, $arah, $nominal, $tanggal, $alasan, $petugas) {
            $this->kunciSantri($santri);
            if ($arah === ArahMutasi::Debit && $santri->saldo() < $nominal) {
                throw new AturanDilanggar('Koreksi debit akan membuat saldo negatif.');
            }

            return TabunganMutasi::create([
                'santri_id' => $santri->id, 'tanggal' => $tanggal, 'arah' => $arah,
                'jenis' => JenisMutasi::Koreksi, 'nominal' => $nominal, 'keterangan' => 'Koreksi: '.$alasan,
                'status' => StatusMutasi::Terverifikasi, 'dicatat_oleh' => $petugas->id,
                'diverifikasi_oleh' => $petugas->id, 'diverifikasi_pada' => CarbonImmutable::now(),
            ]);
        });
    }

    /**
     * Lunasi tagihan potong-otomatis yang sudah terbit (periode <= bulan setoran), urut jatuh tempo lalu urutan_alokasi.
     * Tagihan yang tidak boleh dicicil hanya dibayar bila saldo cukup untuk melunasi.
     *
     * @return int jumlah rupiah yang dialokasikan
     */
    public function alokasiOtomatis(Santri $santri, CarbonInterface $tanggal): int
    {
        $saldo = $santri->saldo();
        if ($saldo <= 0) {
            return 0;
        }
        $batasPeriode = CarbonImmutable::parse($tanggal)->endOfMonth()->toDateString();

        $tagihanList = Tagihan::query()
            ->select('tagihan.*')
            ->join('jenis_tagihan', 'jenis_tagihan.id', '=', 'tagihan.jenis_tagihan_id')
            ->where('tagihan.santri_id', $santri->id)
            ->whereIn('tagihan.status', StatusTagihan::terbuka())
            ->where('jenis_tagihan.potong_otomatis', true)
            ->where(fn ($q) => $q->whereNull('tagihan.periode')->orWhere('tagihan.periode', '<=', $batasPeriode))
            ->orderBy('tagihan.jatuh_tempo')
            ->orderBy('jenis_tagihan.urutan_alokasi')
            ->orderBy('tagihan.id')
            ->get();

        $total = 0;
        foreach ($tagihanList as $tagihan) {
            if ($saldo <= 0) {
                break;
            }
            $sisa = $tagihan->sisa();
            if ($sisa === 0) {
                $tagihan->segarkanStatus();

                continue;
            }
            if (! $tagihan->jenisTagihan->boleh_dicicil && $saldo < $sisa) {
                continue;
            }
            $bayar = min($sisa, $saldo);
            $this->debitTagihan($tagihan, $bayar, $tanggal, null);
            $saldo -= $bayar;
            $total += $bayar;
        }

        return $total;
    }

    /** Dipanggil sekali setiap kredit terverifikasi. */
    private function prosesSetelahKredit(TabunganMutasi $kredit): void
    {
        $santri = $kredit->santri;
        $this->kunciSantri($santri);
        if (in_array($kredit->jenis, [JenisMutasi::SetoranTransfer, JenisMutasi::SetoranTunai], true)) {
            $this->potongPerSetoran($santri, $kredit);
        }
        $this->alokasiOtomatis($santri, $kredit->tanggal);
    }

    /** Infak (dan jenis lain berfrekuensi per_setoran) dipotong setiap ada setoran, kecuali dinonaktifkan. */
    private function potongPerSetoran(Santri $santri, TabunganMutasi $kredit): void
    {
        $ta = TahunAjaran::untukTanggal($kredit->tanggal);
        $jenisList = JenisTagihan::where('frekuensi', Frekuensi::PerSetoran->value)->where('aktif', true)->get();
        foreach ($jenisList as $jenis) {
            if (! $jenis->berlakuUntuk($santri, $kredit->tanggal)) {
                continue;
            }
            $tarif = $jenis->tarifUntuk($ta, $santri->kelasPada($ta), $kredit->tanggal);
            if (! $tarif || $tarif->nominal <= 0) {
                continue;
            }
            Tagihan::create([
                'santri_id' => $santri->id, 'jenis_tagihan_id' => $jenis->id, 'tahun_ajaran_id' => $ta->id,
                'periode' => null, 'keterangan' => $jenis->nama.' setoran #'.$kredit->id,
                'nominal' => $tarif->nominal, 'tarif_id' => $tarif->id,
                'jatuh_tempo' => $kredit->tanggal->toDateString(), 'status' => StatusTagihan::Belum,
            ]);
            // Pelunasannya ikut alokasiOtomatis (jenis per_setoran bertanda potong_otomatis).
        }
    }

    private function debitTagihan(Tagihan $tagihan, int $nominal, CarbonInterface $tanggal, ?User $petugas): TabunganMutasi
    {
        $m = TabunganMutasi::create([
            'santri_id' => $tagihan->santri_id, 'tanggal' => $tanggal,
            'arah' => ArahMutasi::Debit, 'jenis' => JenisMutasi::PembayaranTagihan,
            'nominal' => $nominal, 'keterangan' => 'Bayar: '.$tagihan->keterangan,
            'status' => StatusMutasi::Terverifikasi, 'tagihan_id' => $tagihan->id,
            'dicatat_oleh' => $petugas?->id, 'diverifikasi_pada' => CarbonImmutable::now(),
        ]);
        $tagihan->segarkanStatus();

        return $m;
    }

    private function kunciSantri(Santri $santri): void
    {
        // MySQL/InnoDB: kunci baris santri agar dua setoran bersamaan tidak saling menimpa saldo.
        Santri::whereKey($santri->id)->lockForUpdate()->first();
    }

    private function pastikanPositif(int $nominal): void
    {
        if ($nominal <= 0) {
            throw new AturanDilanggar('Nominal harus lebih dari 0.');
        }
    }
}
