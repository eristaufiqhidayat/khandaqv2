<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keringanan (beasiswa, diskon DSB/DU, dispensasi raport) dan tagihan.
 *
 * Pengganti: tbl_beasiswa, tbl_spp (checklist rp1..rp12), tbl_dsb, tbl_daftar_ulang*,
 * tbl_dsb_saldo_periode, tbl_pts, tbl_pas, tbl_uang_buku, tbl_iuran_loundry.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Semua keringanan melewati alur: diajukan -> disetujui/ditolak oleh Keuangan.
        Schema::create('keringanan', function (Blueprint $table) {
            $table->id();
            $table->string('jenis', 20);            // App\Enums\JenisKeringanan: beasiswa | diskon | dispensasi_raport
            $table->foreignId('santri_id')->constrained('santri')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->foreignId('jenis_tagihan_id')->nullable()->constrained('jenis_tagihan'); // SPP (beasiswa), DSB/DU (diskon)
            $table->foreignId('semester_id')->nullable()->constrained('semester');           // dispensasi raport
            $table->decimal('persen', 5, 2)->nullable();           // potongan persen (beasiswa/diskon)
            $table->unsignedBigInteger('nominal')->nullable();     // potongan rupiah per tagihan (beasiswa/diskon)
            $table->date('berlaku_sampai')->nullable();            // dispensasi: tanggal janji bayar
            $table->string('alasan', 500);
            $table->string('lampiran_path')->nullable();
            $table->string('status', 20)->default('diajukan'); // App\Enums\StatusKeringanan
            $table->foreignId('diajukan_oleh')->constrained('users');
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users');
            $table->timestamp('diputuskan_pada')->nullable();
            $table->string('catatan_keputusan', 500)->nullable();
            $table->timestamps();
            $table->index(['santri_id', 'jenis', 'status']);
        });

        // Satu baris = satu kewajiban. Pembayarannya adalah baris debit di tabungan_mutasi.
        Schema::create('tagihan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('santri_id')->constrained('santri')->cascadeOnDelete();
            $table->foreignId('jenis_tagihan_id')->constrained('jenis_tagihan');
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->foreignId('semester_id')->nullable()->constrained('semester');
            // Kunci periode: tgl 1 bulan (bulanan), tgl mulai semester (per semester),
            // tgl mulai TA (tahunan/sekali). Null untuk tagihan insidental/per setoran.
            $table->date('periode')->nullable();
            $table->string('keterangan', 200);
            $table->unsignedBigInteger('nominal');              // tarif penuh
            $table->unsignedBigInteger('potongan')->default(0); // dari keringanan yang disetujui
            $table->unsignedBigInteger('terbayar')->default(0); // cache: jumlah debit tabungan_mutasi.tagihan_id
            $table->date('jatuh_tempo');
            $table->string('status', 20)->default('belum');     // App\Enums\StatusTagihan
            $table->foreignId('tarif_id')->nullable()->constrained('tarif')->nullOnDelete();
            $table->foreignId('keringanan_id')->nullable()->constrained('keringanan')->nullOnDelete();
            $table->string('legacy_ref', 50)->nullable()->index(); // mis. "tbl_dsb:123"
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['santri_id', 'jenis_tagihan_id', 'tahun_ajaran_id', 'periode'], 'tagihan_periode_unik');
            $table->index(['status', 'jatuh_tempo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tagihan');
        Schema::dropIfExists('keringanan');
    }
};
