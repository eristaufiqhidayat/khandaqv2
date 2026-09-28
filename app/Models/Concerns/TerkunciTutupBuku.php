<?php

namespace App\Models\Concerns;

use App\Models\TutupBuku;

/**
 * Menolak tambah/ubah/hapus transaksi yang tanggalnya jatuh di bulan yang sudah ditutup.
 * Model pemakai wajib punya kolom `tanggal`.
 */
trait TerkunciTutupBuku
{
    public static function bootTerkunciTutupBuku(): void
    {
        static::saving(function ($model) {
            TutupBuku::pastikanTerbuka($model->tanggal);
            if ($model->exists && $model->isDirty('tanggal')) {
                TutupBuku::pastikanTerbuka($model->getOriginal('tanggal'));
            }
        });
        static::deleting(fn ($model) => TutupBuku::pastikanTerbuka($model->getOriginal('tanggal')));
    }
}
