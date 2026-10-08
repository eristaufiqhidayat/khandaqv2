<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nilai harian menjadi H1–H5 (+ Tugas). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nilai', function (Blueprint $table) {
            $table->unsignedTinyInteger('harian_4')->nullable()->after('harian_3');
            $table->unsignedTinyInteger('harian_5')->nullable()->after('harian_4');
        });
    }

    public function down(): void
    {
        Schema::table('nilai', function (Blueprint $table) {
            $table->dropColumn(['harian_4', 'harian_5']);
        });
    }
};
