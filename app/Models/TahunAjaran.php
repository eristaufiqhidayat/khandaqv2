<?php

namespace App\Models;

use App\Casts\Tanggal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tahun ajaran 1 Juli s.d. 30 Juni. */
class TahunAjaran extends Model
{
    protected $table = 'tahun_ajaran';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mulai' => Tanggal::class, 'selesai' => Tanggal::class, 'aktif' => 'boolean'];
    }

    /** Tahun mulai untuk sebuah tanggal: Juli-Desember -> tahun itu, Januari-Juni -> tahun sebelumnya. */
    public static function tahunMulaiUntuk(CarbonInterface $tanggal): int
    {
        return $tanggal->month >= 7 ? $tanggal->year : $tanggal->year - 1;
    }

    public static function namaUntuk(CarbonInterface $tanggal): string
    {
        $y = self::tahunMulaiUntuk($tanggal);

        return $y.'/'.($y + 1);
    }

    /** Ambil (atau buat) tahun ajaran beserta dua semesternya untuk tanggal tertentu. */
    public static function untukTanggal(CarbonInterface $tanggal): self
    {
        $y = self::tahunMulaiUntuk($tanggal);

        $ta = self::firstOrCreate(['tahun_mulai' => $y], [
            'nama' => $y.'/'.($y + 1),
            'mulai' => CarbonImmutable::create($y, 7, 1),
            'selesai' => CarbonImmutable::create($y + 1, 6, 30),
        ]);

        if ($ta->wasRecentlyCreated) {
            $ta->semester()->createMany([
                ['nomor' => 1, 'nama' => Semester::namaBaku(1, $ta->nama), 'mulai' => CarbonImmutable::create($y, 7, 1), 'selesai' => CarbonImmutable::create($y, 12, 31)],
                ['nomor' => 2, 'nama' => Semester::namaBaku(2, $ta->nama), 'mulai' => CarbonImmutable::create($y + 1, 1, 1), 'selesai' => CarbonImmutable::create($y + 1, 6, 30)],
            ]);
        }

        return $ta;
    }

    public static function aktif(): ?self
    {
        return self::where('aktif', true)->first();
    }

    public function semester(): HasMany
    {
        return $this->hasMany(Semester::class);
    }

    public function semesterUntuk(CarbonInterface $tanggal): Semester
    {
        return $this->semester()->where('nomor', $tanggal->month >= 7 ? 1 : 2)->firstOrFail();
    }
}
