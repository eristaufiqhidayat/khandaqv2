<?php

namespace App\Http\Controllers;

use App\Enums\Izin;
use App\Exceptions\AturanDilanggar;
use App\Services\HakAksesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu "Hak akses" (izin hak_akses.kelola): izin per peran. Menu di sidebar mengikuti izin,
 * jadi memindahkan izin = memindahkan menu (mis. Tarif dari Admin ke Keuangan).
 */
class HakAksesController extends Controller
{
    public function __construct(private HakAksesService $svc) {}

    public function index()
    {
        $matriks = $this->svc->matriks();
        unset($matriks['wali_santri']);

        return view('hakakses.index', ['matriks' => $matriks, 'izin' => Izin::cases()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate(['peran' => 'required|string', 'izin' => ['required', Rule::enum(Izin::class)], 'beri' => 'required|boolean']);
        $izin = Izin::from($d['izin']);
        try {
            $peringatan = $this->svc->atur($d['peran'], $izin, (bool) $d['beri'], $request->user());
        } catch (AturanDilanggar $e) {
            return back()->withErrors(['hakakses' => $e->getMessage()]);
        }

        return back()->with('status', ($d['beri'] ? 'Diberikan' : 'Dicabut').": \"{$izin->label()}\" untuk peran {$d['peran']}. ".implode(' ', $peringatan));
    }
}
