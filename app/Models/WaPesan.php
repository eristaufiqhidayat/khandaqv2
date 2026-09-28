<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaPesan extends Model
{
    protected $table = 'wa_pesan';

    protected $guarded = ['id'];

    /** Urutan status dari gateway; status tidak boleh mundur (webhook bisa datang tidak berurutan). */
    public const URUTAN = ['antri' => 0, 'gagal' => 1, 'terkirim' => 2, 'diterima' => 3, 'dibaca' => 4];

    protected function casts(): array
    {
        return ['dikirim_pada' => 'immutable_datetime', 'status_pada' => 'immutable_datetime'];
    }

    public function siaran(): BelongsTo
    {
        return $this->belongsTo(WaSiaran::class, 'wa_siaran_id');
    }

    public function peringatan(): BelongsTo
    {
        return $this->belongsTo(Peringatan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
