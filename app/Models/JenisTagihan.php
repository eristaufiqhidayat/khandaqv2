<?php

namespace App\Models;

use App\Enums\Frekuensi;
use Carbon\CarbonInterface;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JenisTagihan extends Model
{
    use DicatatAudit;

    public const SPP = 'SPP';
    public const LAUNDRY = 'LAUNDRY';
    public const KESEHATAN = 'KESEHATAN';
    public const INFAK = 'INFAK';
    public const DSB = 'DSB';
    public const DU = 'DU';
    public const PTS = 'PTS';
    public const PAS = 'PAS';

    protected $table = 'jenis_tagihan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'frekuensi' => Frekuensi::class,
            'potong_otomatis' => 'boolean',
            'pemicu_kredit' => 'array',
            'wajib_lunas_untuk_raport' => 'boolean',
            'boleh_dicicil' => 'boolean',
            'dihitung_tunggakan' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    public static function kode(string $kode): self
    {
        return self::where('kode', $kode)->firstOrFail();
    }

    public function dana(): BelongsTo
    {
        return $this->belongsTo(Dana::class);
    }

    public function tarif(): HasMany
    {
        return $this->hasMany(Tarif::class);
    }

    /** Tarif yang berlaku: tarif kelas lebih diutamakan dari tarif umum, berlaku_mulai terbaru <= tanggal. */
    /** Jenis kredit yang bisa dipilih sebagai pemicu potongan per setoran (menu Potongan otomatis). */
    public const PILIHAN_PEMICU = [
        'setoran_transfer' => 'Setoran transfer (verifikasi manual / cocok mutasi BSI)',
        'setoran_tunai' => 'Setoran tunai di kasir',
        'transfer_dana' => 'Pindahan dana DSB/DU ke tabungan',
    ];

    /** Bawaan bila belum diatur: setoran transfer & tunai. */
    public const PEMICU_BAWAAN = ['setoran_transfer', 'setoran_tunai'];

    /** @return list<string> jenis kredit (JenisMutasi value) yang memicu potongan per setoran jenis ini */
    public function pemicuKredit(): array
    {
        return $this->pemicu_kredit ?? self::PEMICU_BAWAAN;
    }

    public function tarifUntuk(TahunAjaran $ta, ?Kelas $kelas, CarbonInterface $tanggal): ?Tarif
    {
        return $this->tarif()
            ->where('tahun_ajaran_id', $ta->id)
            ->where('berlaku_mulai', '<=', $tanggal->toDateString())
            ->where(fn ($q) => $q->whereNull('kelas_id')->when($kelas, fn ($q) => $q->orWhere('kelas_id', $kelas->id)))
            ->orderByRaw('CASE WHEN kelas_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('berlaku_mulai')
            ->first();
    }

    /**
     * Apakah jenis ini dijalankan untuk santri pada tanggal tertentu?
     * Tidak dijalankan bila: saklar massal mati, sedang ada jeda (mis. Ramadhan),
     * atau santri punya pengecualian aktif.
     */
    public function berlakuUntuk(Santri $santri, CarbonInterface $tanggal): bool
    {
        if (! $this->aktif) {
            return false;
        }
        $tgl = $tanggal->toDateString();

        $sedangJeda = JedaPotongan::where('jenis_tagihan_id', $this->id)
            ->where('mulai', '<=', $tgl)->where('selesai', '>=', $tgl)->exists();
        if ($sedangJeda) {
            return false;
        }

        return ! PengecualianPotongan::where('jenis_tagihan_id', $this->id)
            ->where('santri_id', $santri->id)
            ->where('mulai', '<=', $tgl)
            ->where(fn ($q) => $q->whereNull('selesai')->orWhere('selesai', '>=', $tgl))
            ->exists();
    }
}
