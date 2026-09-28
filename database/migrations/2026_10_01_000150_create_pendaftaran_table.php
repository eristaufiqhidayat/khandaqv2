<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pendaftaran santri baru (PSB). Diisi calon wali lewat formulir online (atau Admin Office di kantor).
 * Saat diterima, data ini langsung menjadi `santri` (status calon) + akun wali, tanpa diketik ulang.
 * Pengganti: lembaha1_daftar_siswa.tbl_daftar_baru, tbl_siswa_baru, tbl_formulir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pendaftaran', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 20)->unique();                 // PSB-2027-001
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran'); // tahun ajaran tujuan
            // Calon santri
            $table->string('nama_calon', 100);
            $table->string('jenis_kelamin', 10);
            $table->string('tempat_lahir', 50)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('nik_calon', 16)->nullable();
            $table->string('asal_sekolah', 100)->nullable();
            $table->unsignedTinyInteger('tingkat_tujuan')->default(1);
            // Wali
            $table->string('nama_wali', 100);
            $table->string('hubungan_wali', 10)->default('ayah');  // ayah | ibu | wali
            $table->string('nik_wali', 16)->nullable();
            $table->string('telepon_wali', 20);                    // WhatsApp; juga kunci penautan kakak-adik
            $table->string('email_wali', 100)->nullable();
            $table->string('pekerjaan_wali', 100)->nullable();
            $table->text('alamat');
            // Proses
            $table->string('status', 20)->default('baru');         // App\Enums\StatusPendaftaran
            $table->unsignedBigInteger('biaya_formulir')->default(0);
            $table->timestamp('formulir_lunas_pada')->nullable();
            $table->string('bukti_formulir_path')->nullable();
            $table->string('catatan', 500)->nullable();
            $table->foreignId('santri_id')->nullable()->constrained('santri')->nullOnDelete();
            $table->foreignId('diproses_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tahun_ajaran_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pendaftaran');
    }
};
