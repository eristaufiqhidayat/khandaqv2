<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji pegawai (guru & karyawan) yang dibayar lewat payroll BSI (file .txt berpemisah "|").
 * Tidak disentuh Sinkronisasi data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pegawai', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            $table->string('jabatan', 100)->nullable();
            $table->string('no_rekening', 35);                 // BENEFICIARY ACCT (35)
            $table->string('nama_rekening', 100);              // BENEFICIARY ACCT NAME
            $table->string('email', 100)->nullable();          // BENEFICIARY NOTIF EMAIL (100)
            $table->string('telepon', 20)->nullable();         // SMS NOTIF
            $table->unsignedBigInteger('nominal_tetap')->default(0); // usulan nominal tiap bulan
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->boolean('aktif')->default(true);
            $table->string('catatan', 255)->nullable();
            $table->timestamps();
            $table->index(['aktif', 'urutan']);
        });

        Schema::create('penggajian', function (Blueprint $table) {
            $table->id();
            $table->string('judul', 100);                      // "Gaji September 2026"
            $table->date('periode');                           // bulan yang dibayar (tanggal 1)
            $table->date('tanggal_transfer');                  // tanggal di baris pertama file
            $table->string('pesan', 65);                       // MESSAGE (65) bawaan tiap baris
            $table->string('status', 10)->default('draf');     // draf | final
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('difinalkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('difinalkan_pada')->nullable();
            $table->timestamps();
            $table->index('periode');
        });

        Schema::create('penggajian_rincian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('penggajian_id')->constrained('penggajian')->cascadeOnDelete();
            $table->foreignId('pegawai_id')->constrained('pegawai');
            $table->unsignedBigInteger('nominal');
            $table->string('pesan', 65)->nullable();           // kosong = pesan penggajian
            // Salinan data rekening saat difinalkan, agar file lama tetap bisa diunduh ulang persis sama.
            $table->string('no_rekening', 35)->nullable();
            $table->string('nama_rekening', 100)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('telepon', 20)->nullable();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
            $table->unique(['penggajian_id', 'pegawai_id']);
        });

        // Izin baru untuk peran Keuangan (tanpa menimpa pengaturan Hak akses yang sudah diubah Admin).
        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            $id = DB::table('permissions')->where('name', 'gaji.kelola')->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => 'gaji.kelola', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            $peran = DB::table('roles')->where('name', 'keuangan')->value('id');
            if ($peran && ! DB::table('role_has_permissions')->where(['permission_id' => $id, 'role_id' => $peran])->exists()) {
                DB::table('role_has_permissions')->insert(['permission_id' => $id, 'role_id' => $peran]);
            }
            app()['cache']->forget(config('permission.cache.key', 'spatie.permission.cache'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('penggajian_rincian');
        Schema::dropIfExists('penggajian');
        Schema::dropIfExists('pegawai');
        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->where('name', 'gaji.kelola')->value('id');
            if ($id) {
                DB::table('role_has_permissions')->where('permission_id', $id)->delete();
                DB::table('model_has_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
    }
};
