<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rekening extends Model
{
    protected $table = 'rekening';

    protected $guarded = ['id'];

    public static function kode(string $kode): self
    {
        return self::where('kode', $kode)->firstOrFail();
    }
}
