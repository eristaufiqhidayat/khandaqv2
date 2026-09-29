<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Di rekening koran BSI satu Nomor FT bisa muncul di dua baris: transfer dan "Biaya Pemindahbukuan e-Banking"
 * (FT sama, nominal beda). Kunci unik lama (rekening, no_referensi) membuat baris biaya terlewat saat impor.
 * Kunci baru: rekening + referensi + debet + kredit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_mutasi', function (Blueprint $table) {
            // Tambah dulu (MySQL butuh indeks berawalan rekening_id untuk foreign key), baru hapus yang lama.
            $table->unique(['rekening_id', 'no_referensi', 'debet', 'kredit'], 'bank_mutasi_ref_nominal_unique');
        });
        Schema::table('bank_mutasi', function (Blueprint $table) {
            $table->dropUnique(['rekening_id', 'no_referensi']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_mutasi', function (Blueprint $table) {
            $table->unique(['rekening_id', 'no_referensi']);
        });
        Schema::table('bank_mutasi', function (Blueprint $table) {
            $table->dropUnique('bank_mutasi_ref_nominal_unique');
        });
    }
};
