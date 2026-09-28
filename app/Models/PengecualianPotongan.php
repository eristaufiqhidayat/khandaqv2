<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Opsi "tidak dijalankan" untuk satu jenis potongan otomatis pada satu santri. */
class PengecualianPotongan extends Model
{
    use DicatatAudit;

    protected $table = 'pengecualian_potongan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mulai' => Tanggal::class, 'selesai' => Tanggal::class];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class);
    }

    public function jenisTagihan(): BelongsTo
    {
        return $this->belongsTo(JenisTagihan::class);
    }
}
