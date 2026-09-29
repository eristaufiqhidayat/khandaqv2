<?php

namespace App\Services;

use App\Enums\StatusBankMutasi;
use App\Models\BankMutasi;
use App\Models\Rekening;
use App\Support\Dkim;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Notifikasi transaksi BSI lewat email (BSICenter@bankbsi.co.id -> bsi1@lembaharafah.com).
 *
 * Setiap email yang sah (tanda tangan DKIM bankbsi.co.id) diubah menjadi satu baris bank_mutasi,
 * lalu dicocokkan dengan setoran transfer yang dilaporkan wali (nominal + kode unik + tanggal),
 * sama seperti impor CSV, tetapi beberapa menit setelah uang masuk.
 *
 * Isi email (text/html, 7bit):
 *   Berikut Notifikasi untuk Anda : Debit<br>Nomor Rekening : XXXXXX3330<br>Nilai Transaksi : IDR-280,000.00<br>
 *   Tanggal Transaksi : 16 SEP 2026 07:37<br>Nomor Transaksi : FT262590CWFJ<br>...
 */
class NotifikasiEmailBsi
{
    public const DOMAIN = 'bankbsi.co.id';

    public const BARU = 'baru';
    public const DUPLIKAT = 'duplikat';
    public const DITOLAK = 'ditolak';

    private const BULAN = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'MEI' => 5, 'JUN' => 6, 'JUL' => 7,
        'AUG' => 8, 'AGU' => 8, 'AGS' => 8, 'AGT' => 8, 'SEP' => 9, 'OCT' => 10, 'OKT' => 10, 'NOV' => 11, 'NOP' => 11, 'DEC' => 12, 'DES' => 12];

    /** @var (callable(string): ?string)|null */
    private $txt;

    /** @param  (callable(string): ?string)|null  $txt  pencari kunci DKIM (untuk uji; bawaan: DNS) */
    public function __construct(private BankMutasiMatcher $matcher, ?callable $txt = null)
    {
        $this->txt = $txt;
    }

    /**
     * Uraikan isi notifikasi. Null bila email bukan notifikasi transaksi BSI.
     *
     * @return array{arah: 'kredit'|'debit', rekening: string, nominal: int, tanggal: CarbonImmutable, referensi: string}|null
     */
    public function urai(string $raw): ?array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$kepala, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');
        if (preg_match('/^Content-Transfer-Encoding:\s*quoted-printable/mi', $kepala)) {
            $body = quoted_printable_decode($body);
        } elseif (preg_match('/^Content-Transfer-Encoding:\s*base64/mi', $kepala)) {
            $body = (string) base64_decode(preg_replace('/\s+/', '', $body));
        }
        $teks = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $body)));
        $subjek = preg_match('/^Subject:\s*(.+)$/mi', $kepala, $s) ? trim($s[1]) : '';

        $arah = match (true) {
            (bool) preg_match('/Notifikasi untuk Anda\s*:\s*(Kredit|Credit)/i', $teks), (bool) preg_match('/Notifikasi\s*(Kredit|Credit)/i', $subjek) => 'kredit',
            (bool) preg_match('/Notifikasi untuk Anda\s*:\s*(Debit|Debet)/i', $teks), (bool) preg_match('/Notifikasi\s*(Debit|Debet)/i', $subjek) => 'debit',
            default => null,
        };
        if (! $arah
            || ! preg_match('/Nomor Rekening\s*:\s*[X*\s]*(\d{3,})/i', $teks, $rek)
            || ! preg_match('/Nilai Transaksi\s*:\s*(?:IDR|Rp\.?)\s*[+-]?\s*([\d.,]+)/i', $teks, $nilai)
            || ! preg_match('/Tanggal Transaksi\s*:\s*(\d{1,2})\s+([A-Za-z]{3})[A-Za-z]*\s+(\d{4})(?:\s+(\d{1,2})[:.](\d{2}))?/i', $teks, $tgl)
            || ! preg_match('/Nomor Transaksi\s*:\s*([A-Za-z0-9\-\/]+)/i', $teks, $ref)) {
            return null;
        }
        $bulan = self::BULAN[strtoupper($tgl[2])] ?? null;
        $nominal = self::angka($nilai[1]);
        if (! $bulan || $nominal <= 0) {
            return null;
        }

        return [
            'arah' => $arah,
            'rekening' => $rek[1],
            'nominal' => $nominal,
            'tanggal' => CarbonImmutable::create((int) $tgl[3], $bulan, (int) $tgl[1], (int) ($tgl[4] ?? 0), (int) ($tgl[5] ?? 0), 0, 'Asia/Jakarta')->setTimezone(config('app.timezone')), // jam di email = WIB
            'referensi' => BankMutasiMatcher::referensi($ref[1]),
        ];
    }

    /**
     * Proses satu email mentah.
     *
     * @return array{hasil: string, pesan: string, mutasi: ?BankMutasi}
     */
    public function proses(string $raw): array
    {
        $n = $this->urai($raw);
        if (! $n) {
            return $this->hasil(self::DITOLAK, 'Bukan notifikasi transaksi BSI (format tidak dikenali).');
        }
        $rekening = $this->rekening($n['rekening']);
        if (is_string($rekening)) {
            return $this->hasil(self::DITOLAK, $rekening);
        }
        $debet = $n['arah'] === 'debit' ? $n['nominal'] : 0;
        $kredit = $n['arah'] === 'kredit' ? $n['nominal'] : 0;
        // Satu FT bisa berisi transfer + biaya admin (nominal beda), jadi kuncinya FT + nominal.
        if (BankMutasi::where('rekening_id', $rekening->id)->where('no_referensi', $n['referensi'])->where('debet', $debet)->where('kredit', $kredit)->exists()) {
            return $this->hasil(self::DUPLIKAT, "{$n['referensi']} sudah tercatat.");
        }
        if (! preg_match('/^From:.*@'.preg_quote(self::DOMAIN, '/').'\b/mi', str_replace("\r\n", "\n", $raw))
            || ! Dkim::sah($raw, self::DOMAIN, $this->txt)) {
            Log::warning('Notifikasi BSI ditolak: tanda tangan DKIM tidak sah', ['referensi' => $n['referensi'], 'nominal' => $n['nominal']]);

            return $this->hasil(self::DITOLAK, "{$n['referensi']}: tanda tangan email bukan dari ".self::DOMAIN.', diabaikan.');
        }

        $mutasi = DB::transaction(function () use ($n, $rekening, $debet, $kredit) {
            $baris = BankMutasi::create([
                'rekening_id' => $rekening->id, 'tanggal' => $n['tanggal'], 'no_referensi' => $n['referensi'],
                'deskripsi' => 'Notifikasi email BSI',
                'debet' => $debet, 'kredit' => $kredit,
                'status' => StatusBankMutasi::Baru,
            ]);
            $this->matcher->cocokkan($baris);

            return $baris->refresh();
        });
        Storage::disk('local')->put('bsi-email/'.$n['tanggal']->format('Y/m').'/'.$n['referensi'].'.eml', $raw);

        $rp = 'Rp'.number_format($n['nominal'], 0, ',', '.');

        return $this->hasil(self::BARU, "{$n['referensi']} {$n['arah']} {$rp}: {$mutasi->status->value}".($mutasi->catatan ? " ({$mutasi->catatan})" : ''), $mutasi);
    }

    /** Rekening bank aktif yang nomornya berakhiran digit yang terlihat di email (XXXXXX3330). */
    private function rekening(string $akhiran): Rekening|string
    {
        $cocok = Rekening::where('jenis', 'bank')->where('aktif', true)->get()
            ->filter(fn (Rekening $r) => str_ends_with(preg_replace('/\D/', '', (string) $r->nomor), $akhiran));

        return match ($cocok->count()) {
            1 => $cocok->first(),
            0 => "Rekening berakhiran {$akhiran} belum terdaftar. Isi nomor rekening BSI di menu Rekening & akun biaya.",
            default => "Lebih dari satu rekening berakhiran {$akhiran}.",
        };
    }

    /** "280,000.00" / "280.000,00" / "280000" -> 280000 */
    private static function angka(string $s): int
    {
        $s = rtrim($s, '.,');
        if (preg_match('/[.,](\d{2})$/', $s)) {
            $s = substr($s, 0, -3);
        }

        return (int) preg_replace('/\D/', '', $s);
    }

    private function hasil(string $hasil, string $pesan, ?BankMutasi $mutasi = null): array
    {
        return ['hasil' => $hasil, 'pesan' => $pesan, 'mutasi' => $mutasi];
    }
}
