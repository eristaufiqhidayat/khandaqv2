<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaWebhook extends Model
{
    protected $table = 'wa_webhook';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'diproses' => 'boolean'];
    }
}
