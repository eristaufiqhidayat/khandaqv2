<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\AturanDilanggar;
use App\Http\Controllers\Controller;
use App\Services\AkunService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GantiPasswordController extends Controller
{
    public function edit(Request $request)
    {
        return view('auth.ganti-password', ['wajib' => (bool) $request->user()->wajib_ganti_password]);
    }

    public function update(Request $request, AkunService $akun): RedirectResponse
    {
        $data = $request->validate([
            'password_lama' => 'required|string',
            'password' => 'required|string|min:'.AkunService::PANJANG_MINIMAL.'|confirmed',
        ], ['password.confirmed' => 'Ulangi password baru dengan sama persis.']);
        try {
            $akun->gantiPassword($request->user(), $data['password_lama'], $data['password']);
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['password_lama' => $e->getMessage()]);
        }

        return redirect()->route('beranda')->with('status', 'Password berhasil diganti.');
    }
}
