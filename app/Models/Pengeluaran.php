<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Models\Concerns\TerkunciTutupBuku;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengeluaran per dana (pengganti 6 tabel tbl_pengeluaran_*). */
class Pengeluaran extends Model
{
    use DicatatAudit, TerkunciTutupBuku;

    protected $table = 'pengeluaran';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tanggal' => Tanggal::class, 'rutin' => 'boolean', 'nominal' => 'integer'];
    }

    public function dana(): BelongsTo
    {
        return $this->belongsTo(Dana::class);
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class);
    }

    public function akun(): BelongsTo
    {
        return $this->belongsTo(Akun::class);
    }
}
