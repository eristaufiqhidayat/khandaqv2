<?php

namespace App\Http\Controllers;

use App\Support\MenuStaf;
use Illuminate\Http\Request;

/** Setelah login: wali ke portal wali, staf ke menu pertama yang ia punya izinnya. */
class BerandaController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('wali_santri')) {
            return redirect()->route('wali.beranda');
        }
        $awal = MenuStaf::halamanAwal($user);

        return $awal
            ? redirect()->route($awal)
            : response()->view('beranda.tanpa-akses', [], 403);
    }
}
