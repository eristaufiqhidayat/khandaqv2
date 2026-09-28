<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kelas extends Model
{
    protected $table = 'kelas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }
}
