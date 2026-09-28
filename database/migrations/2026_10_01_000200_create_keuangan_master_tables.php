<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master keuangan: dana (dana terikat), rekening kas/bank, akun, pengusul,
 * jenis tagihan, tarif, pengecualian & jeda potongan otomatis.
 *
 * Pengganti: tbl_kode_transaksi, tbl_tarif_spp, tbl_komponen_spp_dsb, tbl_akun,
 * tbl_pengusul, tbl_peserta_loundry, dan tarif yang di-hardcode di kode
 * (laundry Rp100.000, kesehatan Rp50.000, infak Rp15.000).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dana terikat: setiap pemasukan & pengeluaran menempel ke satu dana.
        Schema::create('dana', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();   // SPP, DSB, DU, PTS, PAS, LAUNDRY, KESEHATAN, INFAK, KEGIATAN, FORMULIR, UMUM
            $table->string('nama', 100);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        // Tempat uang fisik berada. Dipakai untuk cashflow & rekonsiliasi.
        Schema::create('rekening', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();   // KAS, BSI
            $table->string('nama', 100);
            $table->string('jenis', 10);            // kas | bank
            $table->string('bank', 50)->nullable();
            $table->string('nomor', 50)->nullable();
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        Schema::create('akun', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();   // tbl_akun.kode_akun
            $table->string('nama', 100);
            $table->timestamps();
        });

        Schema::create('pengusul', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();   // tbl_pengusul.kode_pengusul
            $table->string('nama', 100);
            $table->timestamps();
        });

        Schema::create('jenis_tagihan', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();   // SPP, LAUNDRY, KESEHATAN, INFAK, DSB, DU, PTS, PAS, BUKU, KEGIATAN
            $table->string('nama', 100);
            $table->foreignId('dana_id')->constrained('dana');
            $table->string('frekuensi', 20);        // App\Enums\Frekuensi
            // Dilunasi otomatis dari tabungan begitu ada saldo (SPP, laundry, kesehatan, infak).
            $table->boolean('potong_otomatis')->default(false);
            // Tunggakan jenis ini menahan raport (SPP).
            $table->boolean('wajib_lunas_untuk_raport')->default(false);
            $table->boolean('boleh_dicicil')->default(true);
            // Dihitung ke tahapan warning tunggakan (SPP).
            $table->boolean('dihitung_tunggakan')->default(false);
            // Urutan pelunasan otomatis bila saldo tidak cukup untuk semua tagihan.
            $table->unsignedSmallInteger('urutan_alokasi')->default(100);
            // Saklar massal: false = jenis ini tidak ditagihkan sama sekali.
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        // Besaran sesuai kebijakan, per tahun ajaran (dan per kelas bila perlu).
        Schema::create('tarif', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jenis_tagihan_id')->constrained('jenis_tagihan');
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->foreignId('kelas_id')->nullable()->constrained('kelas'); // null = berlaku semua kelas
            $table->date('berlaku_mulai');          // untuk perubahan tarif di tengah tahun (mis. semester genap)
            $table->unsignedBigInteger('nominal');  // rupiah
            $table->string('keterangan', 200)->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['jenis_tagihan_id', 'tahun_ajaran_id', 'kelas_id', 'berlaku_mulai'], 'tarif_unik');
        });

        // Opsi "tidak dijalankan" per santri (mis. tidak ikut laundry mulai Januari).
        Schema::create('pengecualian_potongan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('santri_id')->constrained('santri')->cascadeOnDelete();
            $table->foreignId('jenis_tagihan_id')->constrained('jenis_tagihan');
            $table->date('mulai');
            $table->date('selesai')->nullable();    // null = sampai dibatalkan
            $table->string('alasan', 255);
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['santri_id', 'jenis_tagihan_id']);
        });

        // Opsi massal sementara (mis. infak diliburkan selama Ramadhan) tanpa mengubah tarif.
        Schema::create('jeda_potongan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jenis_tagihan_id')->constrained('jenis_tagihan');
            $table->date('mulai');
            $table->date('selesai');
            $table->string('alasan', 255);
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jeda_potongan');
        Schema::dropIfExists('pengecualian_potongan');
        Schema::dropIfExists('tarif');
        Schema::dropIfExists('jenis_tagihan');
        Schema::dropIfExists('pengusul');
        Schema::dropIfExists('akun');
        Schema::dropIfExists('rekening');
        Schema::dropIfExists('dana');
    }
};
