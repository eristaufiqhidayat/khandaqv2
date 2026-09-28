<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;

/** Penonaktifan massal sementara satu jenis potongan (mis. infak selama Ramadhan). */
class JedaPotongan extends Model
{
    use DicatatAudit;

    protected $table = 'jeda_potongan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mulai' => Tanggal::class, 'selesai' => Tanggal::class];
    }

    public function jenisTagihan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(JenisTagihan::class);
    }
}
