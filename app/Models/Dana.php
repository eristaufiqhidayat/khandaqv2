<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dana extends Model
{
    protected $table = 'dana';

    protected $guarded = ['id'];

    public static function kode(string $kode): self
    {
        return self::where('kode', $kode)->firstOrFail();
    }
}
