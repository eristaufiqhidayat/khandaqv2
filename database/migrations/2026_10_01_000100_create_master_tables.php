<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master data: tahun ajaran, semester, kelas, santri, riwayat kelas, wali.
 *
 * Pengganti: setup_periode, setup_kelas, data_siswa, tbl_ruangan,
 * tbl_ruangan_periode, tbl_ruangan_20xx, data_orangtua, tbl_akses_ortu.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tahun ajaran selalu 1 Juli s.d. 30 Juni tahun berikutnya.
        Schema::create('tahun_ajaran', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 9)->unique();          // "2025/2026"
            $table->unsignedSmallInteger('tahun_mulai')->unique(); // 2025 (format lama di kolom `tahunajaran`)
            $table->date('mulai');                         // 2025-07-01
            $table->date('selesai');                       // 2026-06-30
            $table->boolean('aktif')->default(false);
            $table->timestamps();
        });

        Schema::create('semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->cascadeOnDelete();
            $table->unsignedTinyInteger('nomor');          // 1 = ganjil (Jul-Des), 2 = genap (Jan-Jun)
            $table->string('nama', 50);
            $table->date('mulai');
            $table->date('selesai');
            $table->boolean('aktif')->default(false);
            $table->unsignedInteger('legacy_id_periode')->nullable()->unique(); // setup_periode.id_periode
            $table->timestamps();
            $table->unique(['tahun_ajaran_id', 'nomor']);
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 20)->unique();          // "1 PUTRA", "4"
            $table->unsignedTinyInteger('tingkat');        // 1..6
            $table->string('jenis_kelamin', 10)->nullable(); // putra | putri | null (campur)
            $table->boolean('aktif')->default(true);
            $table->unsignedInteger('legacy_id_kelas')->nullable()->unique(); // setup_kelas.id_kelas
            $table->timestamps();
        });

        Schema::create('santri', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('legacy_id_siswa')->nullable()->unique(); // data_siswa.id_siswa
            $table->string('nis', 20)->unique();
            $table->string('nisn', 20)->nullable();
            $table->string('nik', 16)->nullable();
            $table->string('nama', 100);
            $table->string('jenis_kelamin', 10);           // laki-laki | perempuan
            $table->string('tempat_lahir', 50)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->text('alamat')->nullable();
            $table->string('telepon', 20)->nullable();
            $table->date('tanggal_masuk')->nullable();
            $table->string('asal_sekolah', 100)->nullable();
            $table->string('status', 20)->default('aktif'); // App\Enums\StatusSantri
            // Kode unik 3 digit untuk transfer (nominal diakhiri kode ini, atau ditulis di berita transfer).
            $table->string('kode_unik', 3)->nullable()->unique();
            $table->text('catatan')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });

        // Satu baris per santri per tahun ajaran (pengganti tbl_ruangan_2022/2023/2024).
        Schema::create('santri_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('santri_id')->constrained('santri')->cascadeOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas');
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->timestamps();
            $table->unique(['santri_id', 'tahun_ajaran_id']);
        });

        // Wali = user dengan role wali_santri. Satu wali bisa punya beberapa anak.
        Schema::create('wali_santri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('santri_id')->constrained('santri')->cascadeOnDelete();
            $table->string('hubungan', 10)->default('wali'); // ayah | ibu | wali
            $table->timestamps();
            $table->unique(['user_id', 'santri_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wali_santri');
        Schema::dropIfExists('santri_kelas');
        Schema::dropIfExists('santri');
        Schema::dropIfExists('kelas');
        Schema::dropIfExists('semester');
        Schema::dropIfExists('tahun_ajaran');
    }
};
