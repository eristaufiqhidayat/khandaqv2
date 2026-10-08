<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul guru: mata pelajaran, guru pengajar per kelas, nilai siswa, catatan harian guru, bank soal pilihan ganda.
 * Kelas dan santri memakai data yang sudah ada (kelas, santri_kelas).
 *
 * Sinkronisasi data lama (masa paralel) mengosongkan kelas/santri/semester, sehingga guru_mengajar dan nilai ikut
 * dikosongkan (lihat MigrasiDataLama::kosongkan). Mapel, bank soal, dan catatan harian tetap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapel', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100)->unique();
            $table->string('kode', 20)->nullable();
            $table->unsignedTinyInteger('kkm')->default(75);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        // Guru X mengajar mapel Y di kelas Z pada tahun ajaran T.
        Schema::create('guru_mengajar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'kelas_id', 'mapel_id', 'tahun_ajaran_id'], 'guru_mengajar_unik');
            $table->index(['tahun_ajaran_id', 'kelas_id', 'mapel_id']);
        });

        // Satu baris per santri per mapel per semester. Kosong (null) = belum diisi.
        Schema::create('nilai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('santri_id')->constrained('santri')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete(); // kelas saat nilai diisi
            $table->unsignedTinyInteger('harian_1')->nullable();
            $table->unsignedTinyInteger('harian_2')->nullable();
            $table->unsignedTinyInteger('harian_3')->nullable();
            $table->unsignedTinyInteger('tugas')->nullable();
            $table->unsignedTinyInteger('uts')->nullable();
            $table->unsignedTinyInteger('uas')->nullable();
            $table->string('catatan_harian', 255)->nullable();
            $table->string('catatan_uts', 255)->nullable();
            $table->string('catatan_uas', 255)->nullable();
            $table->foreignId('diubah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['santri_id', 'mapel_id', 'semester_id']);
            $table->index(['semester_id', 'kelas_id', 'mapel_id']);
        });

        Schema::create('catatan_harian_guru', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('tanggal');
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->nullOnDelete();
            $table->string('kelas_nama', 20)->nullable();    // tetap terbaca bila kelas dibuat ulang oleh sinkronisasi
            $table->foreignId('mapel_id')->nullable()->constrained('mapel')->nullOnDelete();
            $table->string('materi', 200);
            $table->text('kegiatan')->nullable();
            $table->string('tidak_hadir', 255)->nullable();
            $table->text('kendala')->nullable();
            $table->text('tindak_lanjut')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'tanggal']);
        });

        Schema::create('soal_pg', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->unsignedTinyInteger('tingkat');            // 1..6, sama dengan kelas.tingkat
            $table->string('topik', 100)->nullable();
            $table->string('kesulitan', 10)->default('sedang'); // mudah | sedang | sulit
            $table->text('pertanyaan');
            $table->text('opsi_a');
            $table->text('opsi_b');
            $table->text('opsi_c');
            $table->text('opsi_d');
            $table->text('opsi_e')->nullable();
            $table->char('jawaban', 1);                         // a..e
            $table->text('pembahasan')->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['mapel_id', 'tingkat']);
        });

        // Izin & peran baru, tanpa menimpa pengaturan Hak akses yang sudah diubah Admin.
        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            $peranGuru = DB::table('roles')->where('name', 'guru')->value('id')
                ?? DB::table('roles')->insertGetId(['name' => 'guru', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            $peranAdmin = DB::table('roles')->where('name', 'admin')->value('id');
            foreach (['guru.mengajar' => $peranGuru, 'mapel.kelola' => $peranAdmin, 'nilai.lihat_semua' => $peranAdmin] as $izin => $peran) {
                $id = DB::table('permissions')->where('name', $izin)->value('id')
                    ?? DB::table('permissions')->insertGetId(['name' => $izin, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
                if ($peran && ! DB::table('role_has_permissions')->where(['permission_id' => $id, 'role_id' => $peran])->exists()) {
                    DB::table('role_has_permissions')->insert(['permission_id' => $id, 'role_id' => $peran]);
                }
            }
            app()['cache']->forget(config('permission.cache.key', 'spatie.permission.cache'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('soal_pg');
        Schema::dropIfExists('catatan_harian_guru');
        Schema::dropIfExists('nilai');
        Schema::dropIfExists('guru_mengajar');
        Schema::dropIfExists('mapel');
        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->whereIn('name', ['guru.mengajar', 'mapel.kelola', 'nilai.lihat_semua'])->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $id)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $id)->delete();
            DB::table('permissions')->whereIn('id', $id)->delete();
            app()['cache']->forget(config('permission.cache.key', 'spatie.permission.cache'));
        }
    }
};
