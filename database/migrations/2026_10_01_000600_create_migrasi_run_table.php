<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Riwayat & progres modul "Sinkronisasi data lama" (salin ulang dari lembaha1_sino). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migrasi_run', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('antri'); // antri | berjalan | selesai | gagal
            $table->string('tahap', 100)->nullable();        // tahap yang sedang berjalan
            $table->unsignedTinyInteger('persen')->default(0);
            $table->json('ringkasan')->nullable();           // per tabel: sumber, masuk, dilewati, catatan
            $table->json('rekonsiliasi')->nullable();        // saldo lama vs baru
            $table->text('galat')->nullable();
            $table->foreignId('dijalankan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mulai_pada')->nullable();
            $table->timestamp('selesai_pada')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migrasi_run');
    }
};
