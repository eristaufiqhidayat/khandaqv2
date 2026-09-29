<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token login aplikasi Android wali (API /api/v1). Hanya hash SHA-256 yang disimpan; token asli
 * hanya dikirim sekali ke aplikasi saat login. Satu baris per perangkat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_token', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('perangkat', 100)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('terakhir_dipakai_pada')->nullable();
            $table->dateTime('kedaluwarsa_pada'); // dateTime: MySQL strict menolak TIMESTAMP NOT NULL tanpa default
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_token');
    }
};
