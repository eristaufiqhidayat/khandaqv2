<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankImpor extends Model
{
    protected $table = 'bank_impor';

    protected $guarded = ['id'];

    public function baris(): HasMany
    {
        return $this->hasMany(BankMutasi::class);
    }
}
