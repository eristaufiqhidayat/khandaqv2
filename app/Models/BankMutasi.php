<?php

namespace App\Models;

use App\Enums\StatusBankMutasi;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Satu baris mutasi rekening bank hasil impor (CSV/Excel BSI, kelak API). */
class BankMutasi extends Model
{
    use DicatatAudit;

    protected $table = 'bank_mutasi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'immutable_datetime',
            'status' => StatusBankMutasi::class,
            'debet' => 'integer',
            'kredit' => 'integer',
            'saldo' => 'integer',
        ];
    }

    public function setoran(): HasOne
    {
        return $this->hasOne(TabunganMutasi::class);
    }

    public function santriTerdeteksi(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'santri_id_terdeteksi');
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class);
    }
}
