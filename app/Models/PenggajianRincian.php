<?php

namespace App\Models;

use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenggajianRincian extends Model
{
    use DicatatAudit;

    protected $table = 'penggajian_rincian';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['nominal' => 'integer', 'urutan' => 'integer'];
    }

    public function penggajian(): BelongsTo
    {
        return $this->belongsTo(Penggajian::class);
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }
}
