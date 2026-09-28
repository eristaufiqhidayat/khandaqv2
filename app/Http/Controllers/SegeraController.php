<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** Menu yang logikanya sudah ada di app/Services tetapi layarnya belum dibuat. */
class SegeraController extends Controller
{
    public function __invoke(Request $request)
    {
        $menu = collect(config('khandaq-menu'))->firstWhere('route', $request->route()->getName());

        return view('segera', ['judul' => $menu['label'] ?? 'Halaman']);
    }
}
