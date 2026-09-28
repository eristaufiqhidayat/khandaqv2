<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AkunService;
use Tests\KhandaqTestCase;

class PaginasiTest extends KhandaqTestCase
{
    public function test_paginasi_tanpa_svg_tailwind(): void
    {
        foreach (range(1, 35) as $i) {
            $this->santri();
        }
        $office = User::create(['name' => 'Office', 'username' => 'office', 'email' => 'o@test.local',
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole('admin_office');
        $this->actingAs($office)->get(route('santri.index'))->assertOk()
            ->assertSee('class="paginasi"', false)->assertSee('Berikutnya')->assertSee('1–30 dari 35')
            ->assertDontSee('<svg class="w-5 h-5"', false);
    }
}
