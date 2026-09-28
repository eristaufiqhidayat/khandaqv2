<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AkunService;
use App\Support\FotoSantri;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\KhandaqTestCase;

class FotoSantriTest extends KhandaqTestCase
{
    private function staf(string $peran, string $username): User
    {
        return User::create(['name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@test.local",
            'password' => AkunService::hash('rahasia123'), 'wajib_ganti_password' => false])->assignRole($peran);
    }

    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function test_isi_lama_base64_raw_dan_bukan_gambar(): void
    {
        $png = $this->png(1600, 1200);
        [$isi, $ext] = FotoSantri::dariLama(base64_encode($png));
        $this->assertSame('jpg', $ext);
        $this->assertSame([800, 600], array_slice(getimagesizefromstring($isi), 0, 2), 'diperkecil ke sisi terpanjang 800 px');
        $this->assertSame('jpg', FotoSantri::dariLama('data:image/png;base64,'.base64_encode($png))[1]);
        $this->assertSame('jpg', FotoSantri::dariLama($png)[1], 'byte mentah juga diterima');
        $this->assertNull(FotoSantri::dariLama('ADA'));
        $this->assertNull(FotoSantri::dariLama(''));
        $this->assertNull(FotoSantri::dariLama(base64_encode('%PDF-1.4 bukan foto')));
    }

    public function test_unggah_lihat_dan_hapus_foto_dengan_hak_akses(): void
    {
        Storage::fake('local');
        $anak = $this->santri();
        $lain = $this->santri();
        $wali = $this->wali($anak)->assignRole('wali_santri');
        $waliLain = $this->wali($lain)->assignRole('wali_santri');
        $wali->update(['wajib_ganti_password' => false]);
        $waliLain->update(['wajib_ganti_password' => false]);
        $office = $this->staf('admin_office', 'office');

        $this->actingAs($office)->post(route('santri.foto.simpan', $anak), ['foto' => UploadedFile::fake()->createWithContent('a.png', $this->png(2000, 1000))])
            ->assertRedirect(route('santri.show', $anak));
        $path = $anak->fresh()->foto;
        $this->assertStringStartsWith('foto-santri/'.$anak->id.'-', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame([800, 400], array_slice(getimagesizefromstring(Storage::disk('local')->get($path)), 0, 2));
        $this->get(route('santri.show', $anak))->assertOk()->assertSee(route('santri.foto', $anak), false);
        $this->get(route('santri.index'))->assertOk();

        $this->get(route('santri.foto', $anak))->assertOk();
        $this->actingAs($wali)->get(route('santri.foto', $anak))->assertOk();
        $this->actingAs($waliLain)->get(route('santri.foto', $anak))->assertForbidden();
        $this->actingAs($wali)->post(route('santri.foto.hapus', $anak))->assertForbidden();

        // Ganti: file lama terhapus.
        $this->actingAs($office)->post(route('santri.foto.simpan', $anak), ['foto' => UploadedFile::fake()->createWithContent('b.png', $this->png(300, 300))]);
        Storage::disk('local')->assertMissing($path);
        $baru = $anak->fresh()->foto;
        $this->post(route('santri.foto.simpan', $anak), ['foto' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('foto');

        $this->post(route('santri.foto.hapus', $anak));
        $this->assertNull($anak->fresh()->foto);
        Storage::disk('local')->assertMissing($baru);
        $this->get(route('santri.foto', $anak))->assertNotFound();
    }
}
