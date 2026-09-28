<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger tabungan, impor mutasi bank, dan tutup buku.
 *
 * Pengganti: tbl_tabungan_transaksi, tbl_tabungan_siswa, tbl_tabungan_kasbank,
 * tbl_mutasi_bsi. Saldo TIDAK disimpan: saldo = SUM(kredit terverifikasi) - SUM(debit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_impor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rekening_id')->constrained('rekening');
            $table->string('nama_file');
            $table->date('periode_awal')->nullable();
            $table->date('periode_akhir')->nullable();
            $table->unsignedInteger('jumlah_baris')->default(0);
            $table->unsignedInteger('jumlah_cocok')->default(0);
            $table->foreignId('diimpor_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_mutasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_impor_id')->nullable()->constrained('bank_impor')->nullOnDelete();
            $table->foreignId('rekening_id')->constrained('rekening');
            $table->dateTime('tanggal');
            $table->string('no_referensi', 64);
            $table->string('deskripsi', 255)->nullable();
            $table->unsignedBigInteger('debet')->default(0);
            $table->unsignedBigInteger('kredit')->default(0);
            $table->bigInteger('saldo')->nullable();
            $table->string('status', 20)->default('baru'); // App\Enums\StatusBankMutasi
            $table->foreignId('santri_id_terdeteksi')->nullable()->constrained('santri')->nullOnDelete();
            $table->string('catatan', 255)->nullable();
            $table->timestamps();
            $table->unique(['rekening_id', 'no_referensi']);
            $table->index(['status', 'tanggal']);
        });

        Schema::create('tabungan_mutasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('santri_id')->constrained('santri');
            $table->dateTime('tanggal');
            $table->string('arah', 6);                  // kredit | debit
            $table->string('jenis', 30);                // App\Enums\JenisMutasi
            $table->unsignedBigInteger('nominal');
            $table->string('keterangan', 255)->nullable();
            $table->string('status', 20)->default('terverifikasi'); // App\Enums\StatusMutasi
            // Tempat uang fisik bergerak (kas/bank). Null untuk pemindahan internal (bayar tagihan dari saldo).
            $table->foreignId('rekening_id')->nullable()->constrained('rekening');
            // Debit pembayaran tagihan -> tagihan yang dilunasi (cicilan = beberapa baris).
            $table->foreignId('tagihan_id')->nullable()->constrained('tagihan');
            // Koreksi / transfer antar dana (mis. dana DSB dipakai membayar SPP Juli).
            $table->foreignId('dana_id')->nullable()->constrained('dana');
            $table->foreignId('bank_mutasi_id')->nullable()->unique()->constrained('bank_mutasi')->nullOnDelete();
            $table->string('bukti_path')->nullable();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('diverifikasi_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diverifikasi_pada')->nullable();
            // Jejak migrasi dari tbl_tabungan_transaksi.
            $table->unsignedBigInteger('legacy_notransaksi')->nullable()->unique();
            $table->string('legacy_kode', 10)->nullable(); // TKSPP, TDTNI, ...
            $table->string('legacy_ref', 60)->nullable()->index(); // baris tabel samping, mis. "tbl_dsb:12:setor"
            $table->timestamps();
            $table->index(['santri_id', 'status', 'tanggal']);
            $table->index(['tanggal', 'jenis']);
        });

        // Setelah bulan ditutup, transaksi bertanggal <= bulan itu tidak bisa ditambah/diubah/dihapus.
        Schema::create('tutup_buku', function (Blueprint $table) {
            $table->id();
            $table->date('bulan')->unique();              // tgl 1 bulan yang ditutup
            $table->bigInteger('saldo_titipan');          // total saldo tabungan semua santri
            $table->json('saldo_rekening')->nullable();   // {"KAS": 0, "BSI": 0} menurut sistem
            $table->foreignId('ditutup_oleh')->constrained('users');
            $table->string('catatan', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutup_buku');
        Schema::dropIfExists('tabungan_mutasi');
        Schema::dropIfExists('bank_mutasi');
        Schema::dropIfExists('bank_impor');
    }
};
