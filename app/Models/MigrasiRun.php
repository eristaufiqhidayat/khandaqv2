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
