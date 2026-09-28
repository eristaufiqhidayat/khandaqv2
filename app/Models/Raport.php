<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Raport extends Model
{
    protected $table = 'raport';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['diterbitkan_pada' => 'immutable_datetime'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
