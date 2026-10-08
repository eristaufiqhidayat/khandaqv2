<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jurnal mengajar harian guru: materi, kegiatan, santri tidak hadir, kendala, tindak lanjut. */
class CatatanHarianGuru extends Model
{
    protected $table = 'catatan_harian_guru';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tanggal' => 'date'];
    }

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

    public function getNamaKelasAttribute(): ?string
    {
        return $this->kelas?->nama ?? $this->kelas_nama;
    }
}
