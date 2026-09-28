<?php

namespace Tests\Feature;

use App\Services\Migrasi\MigrasiDataLama;
use PHPUnit\Framework\TestCase;

/** Isi raport dari tbl_nilai_akhir: aplikasi lama menyimpan base64, bukan byte PDF. */
class RaportLamaTest extends TestCase
{
    private const PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\n%%EOF";

    public function test_base64_dari_aplikasi_lama_didecode_menjadi_pdf_asli(): void
    {
        [$isi, $mime, $ext] = MigrasiDataLama::isiFileLama(base64_encode(self::PDF));
        $this->assertSame(self::PDF, $isi);
        $this->assertSame(['application/pdf', 'pdf'], [$mime, $ext]);
    }

    public function test_base64_berawalan_data_uri_dan_terpotong_baris(): void
    {
        $b64 = 'data:application/pdf;base64,'.chunk_split(base64_encode(self::PDF), 20, "\r\n");
        $this->assertSame(self::PDF, MigrasiDataLama::isiFileLama($b64)[0]);
    }

    public function test_byte_mentah_dipakai_apa_adanya_dan_gambar_dikenali(): void
    {
        $this->assertSame(self::PDF, MigrasiDataLama::isiFileLama(self::PDF)[0]);
        $png = "\x89PNG\r\n\x1a\n".str_repeat("\0", 8);
        $this->assertSame([$png, 'image/png', 'png'], MigrasiDataLama::isiFileLama(base64_encode($png)));
        $jpg = "\xFF\xD8\xFF\xE0".str_repeat("\0", 8);
        $this->assertSame('jpg', MigrasiDataLama::isiFileLama(base64_encode($jpg))[2]);
    }

    public function test_isi_yang_tidak_dikenali_tidak_dirusak(): void
    {
        $this->assertSame('bukan file', MigrasiDataLama::isiFileLama('bukan file')[0]);
    }
}
