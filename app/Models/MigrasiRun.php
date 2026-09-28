<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MigrasiRun extends Model
{
    protected $table = 'migrasi_run';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ringkasan' => 'array', 'rekonsiliasi' => 'array', 'mulai_pada' => 'immutable_datetime', 'selesai_pada' => 'immutable_datetime'];
    }
}
