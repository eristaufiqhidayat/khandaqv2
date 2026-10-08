<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penugasan: guru mengajar satu mapel di satu kelas pada satu tahun ajaran. Diatur Admin di menu Mapel & guru pengajar. */
class GuruMengajar extends Model
{
    protected $table = 'guru_mengajar';

    protected $guarded = ['id'];

    public function guru(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }

    public function mapel(): BelongsTo
    {
        return $this->belongsTo(Mapel::class);
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    /** Kunci "kelas_id-mapel_id" untuk pilihan di layar. */
    public function getKunciAttribute(): string
    {
        return $this->kelas_id.'-'.$this->mapel_id;
    }
}
