<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Exceptions\PeriodeTerkunci;
use Carbon\CarbonImmutable;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;

class TutupBuku extends Model
{
    use DicatatAudit;

    protected $table = 'tutup_buku';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['bulan' => Tanggal::class, 'saldo_rekening' => 'array', 'saldo_titipan' => 'integer'];
    }

    /** Akhir bulan terakhir yang sudah ditutup, atau null. */
    public static function batasTerkunci(): ?CarbonImmutable
    {
        $bulan = self::max('bulan');

        return $bulan ? CarbonImmutable::parse($bulan)->endOfMonth() : null;
    }

    public static function pastikanTerbuka(mixed $tanggal): void
    {
        if ($tanggal === null) {
            return;
        }
        $batas = self::batasTerkunci();
        $tgl = CarbonImmutable::parse($tanggal);
        if ($batas && $tgl->lessThanOrEqualTo($batas)) {
            throw new PeriodeTerkunci("Periode s.d. {$batas->translatedFormat('F Y')} sudah ditutup; transaksi tanggal {$tgl->toDateString()} tidak dapat diubah.");
        }
    }
}
