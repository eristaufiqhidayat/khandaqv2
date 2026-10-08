<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bank soal pilihan ganda per mapel & tingkat. Dipakai bersama guru se-mapel; hanya pembuatnya yang bisa mengubah. */
class SoalPg extends Model
{
    public const OPSI = ['a', 'b', 'c', 'd', 'e'];

    public const KESULITAN = ['mudah' => 'Mudah', 'sedang' => 'Sedang', 'sulit' => 'Sulit'];

    protected $table = 'soal_pg';

    protected $guarded = ['id'];

    public function mapel(): BelongsTo
    {
        return $this->belongsTo(Mapel::class);
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    /** @return array<string,string> huruf => teks opsi yang terisi */
    public function opsi(): array
    {
        $hasil = [];
        foreach (self::OPSI as $h) {
            if (filled($this->{'opsi_'.$h})) {
                $hasil[$h] = $this->{'opsi_'.$h};
            }
        }

        return $hasil;
    }
}
