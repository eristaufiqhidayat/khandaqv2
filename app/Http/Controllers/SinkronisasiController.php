<?php

namespace App\Http\Controllers;

use App\Enums\Izin;
use App\Jobs\SinkronkanDataLama;
use App\Models\MigrasiRun;
use App\Services\Migrasi\MigrasiDataLama;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Layar "Sinkronisasi data lama" (Admin, izin migrasi.jalankan).
 * Route: GET /sinkronisasi (halaman), POST /sinkronisasi (klik tombol), GET /sinkronisasi/{run} (progres, dipoll tiap 2 detik).
 */
class SinkronisasiController extends Controller
{
    public function index()
    {
        MigrasiRun::bersihkanYangMacet();

        return view('sinkronisasi.index', [
            'riwayat' => MigrasiRun::latest()->limit(10)->get(),
            'mode' => config('khandaq.mode'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['konfirmasi' => 'required|in:GANTI']);
        $svc = new MigrasiDataLama(config('khandaq.koneksi_lama'), config('khandaq.mode'));
        $svc->pastikanBoleh($request->user()); // tolak lebih awal: izin, mode produksi, sudah tutup buku

        MigrasiRun::bersihkanYangMacet();
        if (MigrasiRun::whereIn('status', ['antri', 'berjalan'])->exists()) {
            return response()->json(['pesan' => 'Sinkronisasi lain sedang berjalan.'], 409);
        }
        $run = MigrasiRun::create(['status' => 'antri', 'dijalankan_oleh' => $request->user()->id]);
        SinkronkanDataLama::dispatch($run->id, $request->user()->id);

        return response()->json(['run' => $run->id], 202);
    }

    public function show(MigrasiRun $run): JsonResponse
    {
        abort_unless(request()->user()->hasPermissionTo(Izin::MigrasiJalankan->value), 403);

        return response()->json($run->only(['id', 'status', 'tahap', 'persen', 'ringkasan', 'rekonsiliasi', 'galat', 'mulai_pada', 'selesai_pada']));
    }

    /** Admin menghentikan catatan proses yang macet (mis. pekerja antrean belum dijalankan) agar bisa mengulang. */
    public function batal(Request $request, MigrasiRun $run)
    {
        $run->batalkan('Dibatalkan oleh '.$request->user()->name.'.');

        return redirect()->route('sinkronisasi.index')->with('status', 'Proses sinkronisasi dibatalkan. Tombol bisa dipakai lagi.');
    }
}
