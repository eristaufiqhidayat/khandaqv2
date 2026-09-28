<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/** Audit log (spatie/laravel-activitylog): siapa mengubah apa, nilai lama & baru. */
trait DicatatAudit
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('keuangan');
    }
}
