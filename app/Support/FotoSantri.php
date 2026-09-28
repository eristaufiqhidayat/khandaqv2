<?php

namespace App\Support;

use App\Models\Santri;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto santri disimpan sebagai FILE di disk `local` (storage/app/private/foto-santri/...), bukan BLOB.
 * Kolom santri.foto hanya berisi path. Foto diperkecil (sisi terpanjang 800 px, JPEG) bila ekstensi GD tersedia.
 */
class FotoSantri
{
    public const MAKS_SISI = 800;

    public const FOLDER = 'foto-santri';

    /**
     * Isi kolom data_siswa.image aplikasi lama: teks base64 (base64_encode(file_get_contents(...))),
     * kadang berawalan data:image/...;base64, atau byte gambar mentah.
     *
     * @return array{0:string,1:string}|null [isi, ekstensi] atau null bila bukan gambar
     */
    public static function dariLama(?string $mentah): ?array
    {
        $mentah = (string) $mentah;
        if (trim($mentah) === '') {
            return null;
        }
        if (! self::jenis($mentah)) {
            $b64 = preg_replace('/^data:[^;,]*;base64,/i', '', ltrim($mentah));
            $hasil = base64_decode(preg_replace('/\s+/', '', $b64), true);
            if ($hasil === false || ! self::jenis($hasil)) {
                return null;
            }
            $mentah = $hasil;
        }

        return self::perkecil($mentah);
    }

    /** @return array{0:string,1:string}|null [isi, ekstensi] */
    public static function perkecil(string $isi): ?array
    {
        $jenis = self::jenis($isi);
        if (! $jenis) {
            return null;
        }
        if (! function_exists('imagecreatefromstring')) {
            return [$isi, $jenis];
        }
        $img = @imagecreatefromstring($isi);
        if (! $img) {
            return [$isi, $jenis];
        }
        $img = self::putarSesuaiExif($img, $isi, $jenis);
        [$w, $h] = [imagesx($img), imagesy($img)];
        $skala = min(1, self::MAKS_SISI / max($w, $h));
        [$nw, $nh] = [max(1, (int) round($w * $skala)), max(1, (int) round($h * $skala))];
        $baru = imagecreatetruecolor($nw, $nh);
        imagefill($baru, 0, 0, imagecolorallocate($baru, 255, 255, 255)); // latar putih untuk PNG transparan
        imagecopyresampled($baru, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($baru, null, 82);
        $jpg = (string) ob_get_clean();
        imagedestroy($img);
        imagedestroy($baru);

        // Bila hasil kompresi malah lebih besar (foto sudah kecil), pakai aslinya.
        return ($skala === 1 && $jenis === 'jpg' && strlen($isi) <= strlen($jpg)) ? [$isi, 'jpg'] : [$jpg, 'jpg'];
    }

    /** Simpan unggahan baru untuk santri; foto lama dihapus. */
    public static function simpanUnggahan(Santri $santri, UploadedFile $file): void
    {
        $hasil = self::perkecil((string) file_get_contents($file->getRealPath()));
        if (! $hasil) {
            throw new \App\Exceptions\AturanDilanggar('File bukan gambar JPG/PNG/WebP/GIF.');
        }
        $path = self::FOLDER."/{$santri->id}-".Str::lower(Str::random(8)).".{$hasil[1]}";
        Storage::disk('local')->put($path, $hasil[0]);
        self::hapusFile($santri->foto);
        $santri->update(['foto' => $path]);
    }

    public static function hapus(Santri $santri): void
    {
        self::hapusFile($santri->foto);
        $santri->update(['foto' => null]);
    }

    private static function hapusFile(?string $path): void
    {
        if ($path && str_starts_with($path, self::FOLDER.'/')) {
            Storage::disk('local')->delete($path);
        }
    }

    public static function jenis(string $isi): ?string
    {
        return match (true) {
            str_starts_with($isi, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($isi, "\x89PNG") => 'png',
            str_starts_with($isi, 'GIF8') => 'gif',
            str_starts_with($isi, 'RIFF') && substr($isi, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    /** Foto dari HP sering tersimpan miring; orientasi asli ada di EXIF. */
    private static function putarSesuaiExif(\GdImage $img, string $isi, string $jenis): \GdImage
    {
        if ($jenis !== 'jpg' || ! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($isi));
        $sudut = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;

        return $sudut ? (imagerotate($img, $sudut, 0) ?: $img) : $img;
    }
}
