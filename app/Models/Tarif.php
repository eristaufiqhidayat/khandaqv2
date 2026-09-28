<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tarif extends Model
{
    use DicatatAudit;

    protected $table = 'tarif';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['berlaku_mulai' => Tanggal::class, 'nominal' => 'integer'];
    }

    public function jenisTagihan(): BelongsTo
    {
        return $this->belongsTo(JenisTagihan::class);
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }
}
