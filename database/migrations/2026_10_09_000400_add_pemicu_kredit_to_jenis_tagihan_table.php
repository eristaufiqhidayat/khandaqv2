<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis kredit (uang masuk) yang memicu potongan per setoran, mis. infak.
 * Kosong (null) = bawaan: setoran transfer & setoran tunai (perilaku sebelumnya). Diatur di menu Potongan otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenis_tagihan', function (Blueprint $table) {
            $table->json('pemicu_kredit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jenis_tagihan', function (Blueprint $table) {
            $table->dropColumn('pemicu_kredit');
        });
    }
};
