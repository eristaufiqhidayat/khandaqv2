<?php

namespace App\Models;

use App\Enums\ArahMutasi;
use App\Enums\JenisMutasi;
use App\Enums\StatusMutasi;
use App\Models\Concerns\TerkunciTutupBuku;
use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ledger tabungan santri. Satu-satunya sumber saldo. */
class TabunganMutasi extends Model
{
    use DicatatAudit, TerkunciTutupBuku;

    protected $table = 'tabungan_mutasi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'immutable_datetime',
            'arah' => ArahMutasi::class,
            'jenis' => JenisMutasi::class,
            'status' => StatusMutasi::class,
            'nominal' => 'integer',
            'diverifikasi_pada' => 'immutable_datetime',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class);
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class);
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class);
    }

    public function dana(): BelongsTo
    {
        return $this->belongsTo(Dana::class);
    }

    public function bankMutasi(): BelongsTo
    {
        return $this->belongsTo(BankMutasi::class);
    }
}
