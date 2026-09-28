<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Enums\KeputusanTunggakan;
use App\Enums\TahapPeringatan;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Peringatan extends Model
{
    use DicatatAudit;

    protected $table = 'peringatan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tahap' => TahapPeringatan::class,
            'keputusan' => KeputusanTunggakan::class,
            'bulan_acuan' => Tanggal::class,
            'dikirim_pada' => 'immutable_datetime',
            'dibaca_pada' => 'immutable_datetime',
            'diputuskan_pada' => 'immutable_datetime',
            'total_tunggakan' => 'integer',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class);
    }
}
