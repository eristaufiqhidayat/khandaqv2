<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp: siaran ke wali, log setiap pesan (keluar & masuk), dan webhook mentah dari gateway.
 * Pengganti: lembaha1_daftar_siswa.tbl_groupwa, tbl_nama_wa, tbl_message, tbl_log_status, tbl_webhook, tbl_myapp.
 *
 * Buku telepon terpisah (tbl_nama_wa) TIDAK dipakai lagi: tujuan siaran diambil dari nomor wali di `users`,
 * yang diverifikasi Admin Office. Kredensial gateway (tbl_myapp) pindah ke .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_siaran', function (Blueprint $table) {
            $table->id();
            $table->string('judul', 100);
            $table->text('isi');                                  // boleh memakai {nama_wali}, {nama_santri}
            $table->string('template', 60)->nullable();           // nama template Meta (wajib bila vendor meta)
            $table->json('sasaran');                              // {"jenis":"semua_wali"|"kelas"|"tunggakan", ...}
            $table->string('status', 20)->default('draf');        // draf | mengirim | selesai | dibatalkan
            $table->unsignedInteger('jumlah_tujuan')->default(0);
            $table->unsignedInteger('jumlah_terkirim')->default(0);
            $table->unsignedInteger('jumlah_gagal')->default(0);
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->foreignId('dikirim_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dikirim_pada')->nullable();
            $table->timestamps();
        });

        Schema::create('wa_pesan', function (Blueprint $table) {
            $table->id();
            $table->string('arah', 6)->default('keluar');         // keluar | masuk (balasan wali)
            $table->foreignId('wa_siaran_id')->nullable()->constrained('wa_siaran')->cascadeOnDelete();
            $table->foreignId('peringatan_id')->nullable()->constrained('peringatan')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('telepon', 20);                         // format lokal 08xx
            $table->text('isi');
            $table->string('status', 12)->default('antri');       // antri | terkirim | diterima | dibaca | gagal | masuk
            $table->string('vendor', 12)->nullable();             // ngirimwa | meta | log
            $table->string('id_vendor', 120)->nullable()->unique(); // wamid.* dari Meta
            $table->string('galat', 500)->nullable();
            $table->timestamp('dikirim_pada')->nullable();
            $table->timestamp('status_pada')->nullable();
            $table->timestamps();
            $table->index(['wa_siaran_id', 'status']);
            $table->index(['telepon', 'created_at']);
        });

        Schema::create('wa_webhook', function (Blueprint $table) {
            $table->id();
            $table->string('vendor', 12);
            $table->json('payload');
            $table->boolean('diproses')->default(false);
            $table->string('catatan', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_webhook');
        Schema::dropIfExists('wa_pesan');
        Schema::dropIfExists('wa_siaran');
    }
};
