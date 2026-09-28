<?php

namespace App\Models;

use App\Casts\Tanggal;
use App\Enums\StatusPendaftaran;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pendaftaran extends Model
{
    use DicatatAudit;

    protected $table = 'pendaftaran';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => StatusPendaftaran::class,
            'tanggal_lahir' => Tanggal::class,
            'formulir_lunas_pada' => 'immutable_datetime',
            'biaya_formulir' => 'integer',
        ];
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class);
    }
}
