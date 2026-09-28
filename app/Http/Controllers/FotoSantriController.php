<?php

namespace App\Http\Controllers;

use App\Exceptions\AturanDilanggar;
use App\Models\Santri;
use App\Support\FotoSantri;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Foto santri disajikan lewat aplikasi (bukan folder publik): wali hanya bisa melihat foto anaknya sendiri,
 * staf (siapa pun yang punya minimal satu izin) boleh melihat semua. Unggah/hapus: izin santri.kelola (lihat routes).
 */
class FotoSantriController extends Controller
{
    public function tampil(Request $request, Santri $santri)
    {
        $u = $request->user();
        $boleh = $u->hasRole('wali_santri') ? $u->adalahWaliDari($santri) : $u->getAllPermissions()->isNotEmpty();
        abort_unless($boleh, 403);
        abort_unless($santri->foto && Storage::disk('local')->exists($santri->foto), 404);

        return Storage::disk('local')->response($santri->foto, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function simpan(Request $request, Santri $santri): RedirectResponse
    {
        $request->validate(['foto' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:8192'],
            ['foto.mimes' => 'Foto harus JPG, PNG, WebP, atau GIF.', 'foto.max' => 'Ukuran foto maksimal 8 MB.']);
        try {
            FotoSantri::simpanUnggahan($santri, $request->file('foto'));
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['santri' => $e->getMessage()]);
        }

        return redirect()->route('santri.show', $santri)->with('status', 'Foto '.$santri->nama.' disimpan.');
    }

    public function hapus(Santri $santri): RedirectResponse
    {
        FotoSantri::hapus($santri);

        return redirect()->route('santri.show', $santri)->with('status', 'Foto '.$santri->nama.' dihapus.');
    }
}
