<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raport, peringatan tunggakan, dan pengeluaran per dana.
 *
 * Pengganti: tbl_nilai_akhir (raport BLOB), tbl_pengeluaran + _dsb/_du/_formulir/_pts/_pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raport', function (Blueprint $table) {
            $table->id();
            $table->foreignId('santri_id')->constrained('santri')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester');
            $table->string('jenis', 10);                // pts | pas | pat
            $table->string('file_path');                // Laravel Storage (disk privat)
            $table->string('mime', 100)->default('application/pdf');
            $table->timestamp('diterbitkan_pada')->nullable(); // null = belum terlihat wali
            $table->foreignId('diunggah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('legacy_id_nilai')->nullable()->unique(); // tbl_nilai_akhir.id_nilai
            $table->timestamps();
            $table->unique(['santri_id', 'semester_id', 'jenis']);
        });

        // Log tahapan warning. Satu baris per santri per tahap per bulan acuan (idempoten).
        Schema::create('peringatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('santri_id')->constrained('santri')->cascadeOnDelete();
            $table->string('tahap', 20);                // App\Enums\TahapPeringatan
            $table->date('bulan_acuan');                // tgl 1 bulan saat dikirim
            $table->unsignedTinyInteger('bulan_tunggakan')->default(0);
            $table->unsignedBigInteger('total_tunggakan')->default(0);
            $table->foreignId('tagihan_tertua_id')->nullable()->constrained('tagihan')->nullOnDelete();
            $table->timestamp('dikirim_pada')->nullable();
            $table->timestamp('dibaca_pada')->nullable();     // konfirmasi baca oleh wali (wajib di tahap 3)
            $table->string('keputusan', 20)->nullable();      // App\Enums\KeputusanTunggakan (tahap 3)
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diputuskan_pada')->nullable();
            $table->string('catatan', 500)->nullable();
            $table->timestamps();
            $table->unique(['santri_id', 'tahap', 'bulan_acuan']);
        });

        Schema::create('pengeluaran', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->foreignId('dana_id')->constrained('dana');
            $table->foreignId('rekening_id')->constrained('rekening');
            $table->foreignId('akun_id')->nullable()->constrained('akun');
            $table->foreignId('pengusul_id')->nullable()->constrained('pengusul');
            $table->boolean('rutin')->default(false);
            $table->unsignedBigInteger('nominal');
            $table->string('keterangan', 255)->nullable();
            $table->string('bukti_path')->nullable();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('legacy_ref', 50)->nullable()->unique(); // "tbl_pengeluaran_dsb:12"
            $table->timestamps();
            $table->index(['tanggal', 'dana_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengeluaran');
        Schema::dropIfExists('peringatan');
        Schema::dropIfExists('raport');
    }
};
