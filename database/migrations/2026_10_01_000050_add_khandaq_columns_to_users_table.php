<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu tabel users untuk semua peran (admin, admin_office, keuangan, wali_santri).
 * Peran & izin memakai spatie/laravel-permission.
 * Pengganti: user_admin, user_superadmin, data_orangtua (akun), users Myth/Auth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
            $table->string('telepon', 20)->nullable()->after('email'); // nomor WhatsApp untuk notifikasi
            // Nomor baru yang diajukan wali; berlaku setelah diverifikasi Admin Office (nomor ini dipakai untuk pemberitahuan resmi).
            $table->string('telepon_menunggu', 20)->nullable()->after('telepon');
            $table->boolean('aktif')->default(true)->after('telepon');
            $table->string('nik', 16)->nullable();
            $table->text('alamat')->nullable();
            $table->string('pekerjaan', 100)->nullable();
            // Password awal/reset dibuat sistem; pemilik wajib menggantinya saat login pertama.
            $table->boolean('wajib_ganti_password')->default(true);
            $table->timestamp('password_diubah_pada')->nullable();
            // Hash Myth/Auth dari aplikasi lama (bcrypt atas base64(sha384(password))). Dipakai sekali saat wali
            // pertama kali masuk dengan password lamanya, lalu diganti hash Laravel dan dikosongkan.
            $table->string('password_lama')->nullable();
            $table->unsignedInteger('legacy_id_orangtua')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['legacy_id_orangtua']);
            $table->dropColumn(['username', 'telepon', 'telepon_menunggu', 'aktif', 'nik', 'alamat', 'pekerjaan', 'wajib_ganti_password', 'password_diubah_pada', 'password_lama', 'legacy_id_orangtua']);
        });
    }
};
