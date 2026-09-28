<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Enums\ArahMutasi;
use App\Enums\StatusMutasi;
use App\Enums\StatusSantri;
use App\Enums\StatusTagihan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Santri extends Model
{
    use SoftDeletes;

    protected $table = 'santri';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => StatusSantri::class,
            'tanggal_lahir' => Tanggal::class,
            'tanggal_masuk' => Tanggal::class,
        ];
    }

    public function scopeAktif(Builder $q): Builder
    {
        return $q->where('status', StatusSantri::Aktif->value);
    }

    public function riwayatKelas(): HasMany
    {
        return $this->hasMany(SantriKelas::class);
    }

    public function kelasPada(TahunAjaran $ta): ?Kelas
    {
        return $this->riwayatKelas()->where('tahun_ajaran_id', $ta->id)->first()?->kelas;
    }

    public function wali(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wali_santri')->withPivot('hubungan')->withTimestamps();
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(TabunganMutasi::class);
    }

    public function tagihan(): HasMany
    {
        return $this->hasMany(Tagihan::class);
    }

    public function keringanan(): HasMany
    {
        return $this->hasMany(Keringanan::class);
    }

    public function raport(): HasMany
    {
        return $this->hasMany(Raport::class);
    }

    /**
     * Saldo tabungan dihitung dari ledger, tidak pernah disimpan.
     * Kredit dihitung hanya yang terverifikasi; debit selalu dihitung.
     */
    public function saldo(?CarbonInterface $sampai = null): int
    {
        $q = $this->mutasi()->where('status', StatusMutasi::Terverifikasi->value);
        if ($sampai) {
            $q->where('tanggal', '<=', $sampai);
        }

        return (int) $q->selectRaw(
            "COALESCE(SUM(CASE WHEN arah = ? THEN nominal ELSE -nominal END), 0) AS saldo",
            [ArahMutasi::Kredit->value]
        )->value('saldo');
    }

    /** Tagihan terbuka yang sudah lewat jatuh tempo. */
    public function tunggakan(CarbonInterface $per): HasMany
    {
        return $this->tagihan()
            ->whereIn('status', StatusTagihan::terbuka())
            ->where('jatuh_tempo', '<', $per->toDateString());
    }
}
