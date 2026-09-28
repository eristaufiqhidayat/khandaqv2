<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kalender akademik (pengganti tbl_kalender_akedemik aplikasi lama), tampil di portal wali. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kalender_akademik', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai')->nullable();     // null = satu hari
            $table->string('kegiatan', 200);
            $table->string('keterangan', 500)->nullable();
            $table->unsignedInteger('legacy_no')->nullable()->unique();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('tanggal_mulai');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kalender_akademik');
    }
};
