<?php

namespace App\Models;

use App\Models\Concerns\DicatatAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaSiaran extends Model
{
    use DicatatAudit;

    protected $table = 'wa_siaran';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sasaran' => 'array', 'dikirim_pada' => 'immutable_datetime'];
    }

    public function pesan(): HasMany
    {
        return $this->hasMany(WaPesan::class);
    }

    /** Hitung ulang dari wa_pesan (dipanggil setelah setiap kiriman & status webhook). */
    public function segarkanJumlah(): void
    {
        $per = $this->pesan()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->all();
        $antri = (int) ($per['antri'] ?? 0);
        $this->update([
            'jumlah_terkirim' => (int) (($per['terkirim'] ?? 0) + ($per['diterima'] ?? 0) + ($per['dibaca'] ?? 0)),
            'jumlah_gagal' => (int) ($per['gagal'] ?? 0),
            'status' => $this->status === 'mengirim' && $antri === 0 ? 'selesai' : $this->status,
        ]);
    }
}
