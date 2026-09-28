<?php

namespace App\Http\Controllers\Wali;

use App\Exceptions\AturanDilanggar;
use App\Http\Controllers\Controller;
use App\Services\DataWaliService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Portal wali: ubah alamat, pekerjaan, email sendiri; nomor WhatsApp baru menunggu verifikasi Admin Office. */
class ProfilController extends Controller
{
    public function edit(Request $request)
    {
        return view('wali.profil', ['wali' => $request->user()]);
    }

    public function update(Request $request, DataWaliService $svc): RedirectResponse
    {
        $d = $request->validate([
            'alamat' => 'nullable|string|max:300', 'pekerjaan' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:100', 'telepon' => 'nullable|string|max:20',
        ]);
        $wali = $request->user();
        $teleponLama = $wali->telepon;
        try {
            $svc->ubahProfil($wali, $d);
        } catch (AturanDilanggar $e) {
            return back()->withInput()->withErrors(['profil' => $e->getMessage()]);
        }
        $pesan = 'Profil disimpan.';
        if ($wali->telepon_menunggu) {
            $pesan .= " Nomor WhatsApp baru {$wali->telepon_menunggu} menunggu verifikasi pondok; sementara pemberitahuan tetap dikirim ke {$teleponLama}.";
        }

        return redirect()->route('wali.beranda')->with('status', $pesan);
    }
}
