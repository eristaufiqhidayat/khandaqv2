<?php

namespace App\Http\Controllers\Wali;

use App\Http\Controllers\Controller;
use App\Models\Semester;
use App\Models\Tagihan;
use App\Services\AksesRaport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Portal wali: saldo, tagihan terbuka, dan raport untuk setiap anak (hanya anak sendiri). */
class PortalController extends Controller
{
    public function __invoke(Request $request, AksesRaport $akses)
    {
        $sekarang = CarbonImmutable::now();
        $anak = $request->user()->anak()->with(['raport.semester'])->orderBy('nama')->get()->map(fn ($s) => [
            'santri' => $s,
            'saldo' => $s->saldo(),
            'tagihan' => Tagihan::terbuka()->where('santri_id', $s->id)->orderBy('jatuh_tempo')->get(),
            'raport' => $s->raport->whereNotNull('diterbitkan_pada')->sortByDesc('semester_id')->map(fn ($r) => [
                'raport' => $r, 'terkunci' => $akses->alasanTerkunci($s, $r->semester, $sekarang),
            ]),
        ]);

        return view('wali.beranda', ['anak' => $anak, 'sekarang' => $sekarang]);
    }
}
