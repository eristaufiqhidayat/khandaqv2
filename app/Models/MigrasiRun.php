<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class MigrasiRun extends Model
{
    protected $table = 'migrasi_run';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ringkasan' => 'array', 'rekonsiliasi' => 'array', 'mulai_pada' => 'immutable_datetime', 'selesai_pada' => 'immutable_datetime'];
    }

    public const MENIT_MACET = 35; // sedikit di atas batas waktu job (30 menit)

    public function aktif(): bool
    {
        return in_array($this->status, ['antri', 'berjalan'], true);
    }

    /** Antri tetapi belum diambil pekerja antrean lebih dari 1 menit: kemungkinan `queue:work` belum berjalan. */
    public function menungguPekerja(): bool
    {
        return $this->status === 'antri' && $this->created_at?->lt(CarbonImmutable::now()->subMinute());
    }

    /**
     * Catat tahap & persen saat sinkronisasi berjalan. Sinkronisasi berlangsung di dalam SATU transaksi besar;
     * bila progres ditulis lewat koneksi yang sama, layar (koneksi lain) tidak melihatnya sampai transaksi selesai
     * sehingga bilah progres diam di 0%. Karena itu progres ditulis lewat koneksi terpisah yang langsung tersimpan.
     */
    public function catatProgres(string $tahap, int $persen): void
    {
        $persen = max(0, min(99, $persen)); // 100% hanya saat benar-benar selesai
        $data = ['tahap' => $tahap, 'persen' => $persen, 'updated_at' => CarbonImmutable::now()];

        $koneksi = self::koneksiProgres();
        if ($koneksi === null) { // mis. SQLite :memory: saat tes — koneksi kedua berarti basis data lain
            $this->forceFill($data)->save();

            return;
        }
        \Illuminate\Support\Facades\DB::connection($koneksi)->table($this->getTable())->where('id', $this->id)->update($data);
        $this->forceFill($data)->syncOriginal();
    }

    /** Nama koneksi kedua (salinan pengaturan koneksi bawaan), atau null bila tidak bisa dipakai. */
    private static function koneksiProgres(): ?string
    {
        $bawaan = config('database.default');
        $cfg = config("database.connections.{$bawaan}");
        if (! is_array($cfg) || (($cfg['driver'] ?? null) === 'sqlite' && in_array($cfg['database'] ?? '', [':memory:', ''], true))) {
            return null;
        }
        config(['database.connections.khandaq_progres' => $cfg]);

        return 'khandaq_progres';
    }

    /** Lama berjalan dalam detik (untuk tampilan). */
    public function lamaDetik(): ?int
    {
        if (! $this->mulai_pada) {
            return null;
        }

        return (int) $this->mulai_pada->diffInSeconds($this->selesai_pada ?? CarbonImmutable::now(), true);
    }

    public function batalkan(string $alasan): void
    {
        if ($this->aktif()) {
            $this->update(['status' => 'gagal', 'galat' => $alasan, 'selesai_pada' => CarbonImmutable::now()]);
        }
    }

    /**
     * Proses yang mati di tengah jalan (kehabisan memori, server restart) tidak sempat menulis status "gagal".
     * Run yang tidak bergerak lebih dari MENIT_MACET menit dianggap gagal agar tombol bisa dipakai lagi.
     */
    public static function bersihkanYangMacet(): void
    {
        static::whereIn('status', ['antri', 'berjalan'])
            ->where('updated_at', '<', CarbonImmutable::now()->subMinutes(self::MENIT_MACET))
            ->get()->each->batalkan('Terhenti tanpa kabar (pekerja antrean mati, kehabisan memori, atau server dimulai ulang).');
    }
}
