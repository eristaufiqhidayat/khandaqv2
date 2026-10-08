<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bobot nilai akhir (persen) per mapel, bisa diubah Admin di menu Mapel & guru pengajar. Jumlahnya 100. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mapel', function (Blueprint $table) {
            $table->unsignedTinyInteger('bobot_harian')->default(50)->after('kkm');
            $table->unsignedTinyInteger('bobot_uts')->default(25)->after('bobot_harian');
            $table->unsignedTinyInteger('bobot_uas')->default(25)->after('bobot_uts');
        });
    }

    public function down(): void
    {
        Schema::table('mapel', function (Blueprint $table) {
            $table->dropColumn(['bobot_harian', 'bobot_uts', 'bobot_uas']);
        });
    }
};
