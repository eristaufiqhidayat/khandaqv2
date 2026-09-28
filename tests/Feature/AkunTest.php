<?php

namespace Tests\Feature;

use App\Enums\Izin;
use App\Exceptions\AturanDilanggar;
use App\Services\AkunService;
use Tests\KhandaqTestCase;

class AkunTest extends KhandaqTestCase
{
    public function test_admin_membuat_akun_staf_dengan_password_acak_yang_wajib_diganti(): void
    {
        $admin = $this->beriIzin($this->buatUser('Admin'), Izin::PenggunaKelola);
        $svc = new AkunService();
        ['user' => $u, 'password_awal' => $pw] = $svc->buatStaf('Ustadzah Rina', 'rina', 'rina@pondok.test', '0812 1111 2222', 'admin_office', $admin);

        $this->assertSame(10, strlen($pw));
        $this->assertNotSame($pw, $u->password, 'tersimpan sebagai hash');
        $this->assertTrue((bool) $u->wajib_ganti_password);
        $this->assertTrue($u->hasRole('admin_office'));

        $svc->gantiPassword($u, $pw, 'RahasiaBaru1');
        $this->assertFalse((bool) $u->fresh()->wajib_ganti_password);
        $this->assertTrue(password_verify('RahasiaBaru1', $u->fresh()->password));
    }

    public function test_admin_office_hanya_boleh_reset_akun_wali_bukan_staf(): void
    {
        $ao = $this->beriIzin($this->buatUser('TU'), Izin::AkunWaliReset);
        $wali = $this->wali($this->santri());
        $wali->assignRole('wali_santri');
        $staf = $this->buatUser('Keuangan');
        $svc = new AkunService();

        $tmp = $svc->resetPassword($wali, $ao);
        $this->assertTrue(password_verify($tmp, $wali->fresh()->password));
        $this->assertTrue((bool) $wali->fresh()->wajib_ganti_password);

        $this->expectException(AturanDilanggar::class);
        $svc->resetPassword($staf, $ao);
    }

    public function test_password_baru_minimal_8_karakter(): void
    {
        $u = $this->buatUser('X');
        $u->update(['password' => AkunService::hash('lama12345')]);
        $this->expectException(AturanDilanggar::class);
        (new AkunService())->gantiPassword($u, 'lama12345', 'pendek');
    }
}
