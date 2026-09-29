<?php

namespace Database\Seeders;

use App\Enums\Izin;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Peran & izin (spatie/laravel-permission). Wali santri tidak diberi izin staf;
 * aksesnya diatur policy (hanya data anaknya sendiri).
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (Izin::cases() as $izin) {
            Permission::findOrCreate($izin->value, 'web');
        }

        $peran = [
            'admin' => [Izin::TarifKelola, Izin::BeasiswaAjukan, Izin::PengecualianKelola, Izin::TagihanKelola,
                Izin::RaportUnggah, Izin::RaportLihatSemua, Izin::LaporanLihat,
                Izin::KelasKelola, Izin::PeriodeKelola, Izin::PenggunaKelola, Izin::HakAksesKelola, Izin::MigrasiJalankan],
            'admin_office' => [Izin::SetoranCatat, Izin::SetoranVerifikasi, Izin::PenarikanCatat, Izin::TagihanKelola,
                Izin::KeringananAjukan, Izin::RaportUnggah, Izin::RaportLihatSemua, Izin::SantriKelola,
                Izin::PendaftaranProses, Izin::DataWaliVerifikasi, Izin::AkunWaliReset, Izin::WaSiaran],
            // Keuangan level manager: satu-satunya penyetuju beasiswa, diskon DSB/DU, dan dispensasi raport.
            'keuangan' => [Izin::KeringananSetujui, Izin::BankImpor, Izin::SetoranVerifikasi, Izin::PengeluaranCatat,
                Izin::TutupBuku, Izin::TunggakanPutuskan, Izin::LaporanLihat, Izin::RaportLihatSemua,
                Izin::MasterKeuanganKelola, Izin::GajiKelola],
            'wali_santri' => [],
        ];
        foreach ($peran as $nama => $izin) {
            Role::findOrCreate($nama, 'web')->syncPermissions(array_map(fn (Izin $i) => $i->value, $izin));
        }
    }
}
